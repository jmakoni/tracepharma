<?php

namespace App\Actions\Receiving;

use App\Actions\Epcis\RecordAtpSoftWarning;
use App\Actions\Epcis\RecordSbdhOwningPartyMismatch;
use App\Actions\Epcis\RecordScheduledProductMissingDea;
use App\Enums\ReceivingSessionKind;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Custody\PrincipalCustody;
use App\Support\Receiving\CmoOwnProductInbound;
use App\Support\Receiving\InboundExpectedLineClaims;
use App\Support\Receiving\ResolveReceivingSite;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class OpenReceivingSessionFromDocument
{
    public function __construct(
        private readonly RecordAtpSoftWarning $recordAtpSoftWarning,
        private readonly RecordScheduledProductMissingDea $recordScheduledProductMissingDea,
        private readonly RecordSbdhOwningPartyMismatch $recordSbdhOwningPartyMismatch,
        private readonly ReceivingGate $receivingGate,
        private readonly ResolveReceivingSite $resolveReceivingSite,
        private readonly PropagateScanFirstConfirmsToAsnSession $propagateScanFirstConfirmsToAsnSession,
        private readonly AttachInboundDocumentToShipment $attachInboundDocumentToShipment,
        private readonly ExpandReceivingSessionExpectedParents $expandExpectedParents,
        private readonly SyncInboundExpectedLinesFromDocument $syncInboundExpectedLinesFromDocument,
        private readonly ResolveAsnRootParentEpcIds $resolveAsnRootParentEpcIds,
    ) {}

    public function handle(
        EpcisDocument $document,
        ?int $siteId = null,
        ?int $openedBy = null,
        ?int $principalId = null,
        bool $asSystem = false,
    ): ReceivingSession {
        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $allowed = $requireValidated ? ['validated'] : ['parsed', 'validated'];

        if (! in_array($document->status, $allowed, true)) {
            throw new InvalidArgumentException(
                $requireValidated
                    ? "Receiving session requires document status validated; got [{$document->status}]."
                    : "Receiving session requires document status parsed or validated; got [{$document->status}].",
            );
        }

        if (
            config('tracepharma.epcis.enforce_ts_for_receiving')
            && ! (bool) $document->dscsa_affirm
            && ! CmoOwnProductInbound::applies($document)
        ) {
            throw new DomainException(
                'Cannot open receiving: document lacks DSCSA transaction statement affirmation (TS).',
            );
        }

        if (! TenantFeatures::forTenant(tenant())->supportsReceiving()) {
            throw new DomainException('Receiving is not enabled for this organization profile.');
        }

        if (! $asSystem && ! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $this->recordAtpSoftWarning->handle($document);
        $this->recordSbdhOwningPartyMismatch->handle($document);
        $this->recordScheduledProductMissingDea->handle($document);

        // Re-derive destination GLN mismatch before gating (clears stale / emits missing).
        $blockingCase = $this->receivingGate->documentBlockedAfterDestinationRecheck($document);
        if ($blockingCase !== null) {
            $type = $blockingCase->type?->name ?? $blockingCase->type?->code ?? 'exception';
            throw new DomainException(
                "Cannot open receiving: open document-wide exception #{$blockingCase->getKey()} ({$type}) blocks this file until resolved.",
            );
        }

        $resolvedSiteId = $this->resolveReceivingSite->handle($document, $siteId);
        $resolvedPrincipalId = PrincipalCustody::forTenant()->resolveSessionPrincipalId(
            $resolvedSiteId,
            $principalId,
        );

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, $resolvedSiteId);
        }

        if (
            Schema::hasColumn('epcis_documents', 'inbound_shipment_id')
            && $document->inbound_shipment_id === null
            && (string) ($document->direction ?? '') === 'inbound'
        ) {
            $this->attachInboundDocumentToShipment->handle($document);
            $document = $document->refresh();
        }

        $shipmentId = Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')
            && $document->inbound_shipment_id !== null
            ? (int) $document->inbound_shipment_id
            : null;

        $shipment = $shipmentId !== null
            ? InboundShipment::query()->find($shipmentId)
            : null;

        if ($shipment !== null && Schema::hasTable('inbound_expected_lines')) {
            $this->syncInboundExpectedLinesFromDocument->handle($document, $shipment);
            $shipment = $shipment->fresh() ?? $shipment;
        }

        $allowParallel = TenantSettings::forTenant(tenant())->allowParallelSessions();

        // Setting off: resume existing open/in_progress on this shipment (today).
        // Setting on: skip resume so a second opener gets a NEW session.
        if ($shipmentId !== null && ! $allowParallel) {
            $shipmentSession = ReceivingSession::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->whereIn('status', ['open', 'in_progress'])
                ->orderByDesc('id')
                ->first();

            if ($shipmentSession !== null) {
                $shipmentSession = $this->maybeUpdateOpenSessionSite(
                    $shipmentSession,
                    $resolvedSiteId,
                    $siteId,
                );
                $shipmentSession = $this->ensureSessionPrincipal(
                    $shipmentSession,
                    $resolvedSiteId,
                    $resolvedPrincipalId,
                );

                $rootParentIds = $this->resolveSessionParentEpcIds(
                    $document,
                    $shipment,
                    $allowed,
                    (int) $shipmentSession->getKey(),
                );
                $this->expandExpectedParents->handle($shipmentSession, $rootParentIds);

                if (in_array($shipmentSession->status, ['open', 'in_progress'], true)) {
                    $this->propagateScanFirstConfirmsToAsnSession->handle($shipmentSession->fresh(), $openedBy);
                }

                return $shipmentSession->fresh();
            }
        }

        // Document-level resume only when not opening a parallel session on a shipment.
        if (! ($allowParallel && $shipmentId !== null)) {
            $existingOpen = ReceivingSession::query()
                ->where('epcis_document_id', $document->getKey())
                ->whereIn('status', ['open', 'in_progress'])
                ->orderByDesc('id')
                ->first();

            if ($existingOpen !== null) {
                $existingOpen = $this->maybeUpdateOpenSessionSite(
                    $existingOpen,
                    $resolvedSiteId,
                    $siteId,
                );
                $existingOpen = $this->ensureSessionPrincipal(
                    $existingOpen,
                    $resolvedSiteId,
                    $resolvedPrincipalId,
                );

                if ($shipment !== null) {
                    $rootParentIds = $this->resolveSessionParentEpcIds(
                        $document,
                        $shipment,
                        $allowed,
                        (int) $existingOpen->getKey(),
                    );
                    $this->expandExpectedParents->handle($existingOpen, $rootParentIds);
                }

                $this->propagateScanFirstConfirmsToAsnSession->handle($existingOpen->fresh(), $openedBy);

                return $existingOpen->fresh();
            }
        }

        $existingCancelled = ReceivingSession::query()
            ->where('epcis_document_id', $document->getKey())
            ->where('status', 'cancelled')
            ->orderByDesc('id')
            ->first();

        if ($existingCancelled !== null) {
            $existingCancelled = $this->reopenCancelledInboundAsnSession(
                $existingCancelled,
                $document,
                $resolvedSiteId,
                $allowed,
                $resolvedPrincipalId,
            );

            if (in_array($existingCancelled->status, ['open', 'in_progress'], true)) {
                $this->propagateScanFirstConfirmsToAsnSession->handle($existingCancelled->fresh(), $openedBy);
            }

            return $existingCancelled->fresh();
        }

        $existingCompleted = ReceivingSession::query()
            ->where('epcis_document_id', $document->getKey())
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        if ($existingCompleted !== null) {
            $hasRemaining = $shipment !== null
                && Schema::hasTable('inbound_expected_lines')
                && $shipment->hasRemainingExpected();

            if (! $hasRemaining) {
                // Soft return of completed session when the order has nothing left.
                return $existingCompleted->fresh();
            }
            // Day-2+: create a new session for remaining expected parents (do not reuse completed).
        }

        $rootParentIds = $this->resolveSessionParentEpcIds($document, $shipment, $allowed);

        $session = DB::transaction(function () use (
            $document,
            $resolvedSiteId,
            $resolvedPrincipalId,
            $openedBy,
            $rootParentIds,
            $shipmentId,
            $allowParallel,
        ): ReceivingSession {
            $attributes = [
                'session_kind' => ReceivingSessionKind::InboundAsn,
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => $resolvedSiteId,
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_by' => $openedBy,
                'opened_at' => now(),
            ];

            if ($shipmentId !== null) {
                $attributes['inbound_shipment_id'] = $shipmentId;
            }

            if (
                $resolvedPrincipalId !== null
                && TenantFeatures::forTenant(tenant())->supportsPrincipals()
            ) {
                $attributes['principal_id'] = $resolvedPrincipalId;
            }

            $session = ReceivingSession::query()->create($attributes);

            // Parallel on: every opener starts empty (claim EPCs on scan), including first
            // opener and day-2 / next shift. Parallel off: seed remaining expected parents.
            $seedOnOpen = ! ($allowParallel && $shipmentId !== null);

            if ($seedOnOpen) {
                $claimedParentIds = InboundExpectedLineClaims::claimExpectedParents($session, $rootParentIds);

                $now = now();
                $rows = [];
                foreach ($claimedParentIds as $epcId) {
                    $rows[] = [
                        'receiving_session_id' => $session->getKey(),
                        'epc_id' => $epcId,
                        'parent_epc_id' => null,
                        'line_role' => 'parent',
                        'status' => 'expected',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    ReceivingScanLine::query()->insert($rows);
                }

                $session->forceFill([
                    'expected_parent_count' => count($claimedParentIds),
                ])->save();
            }

            if ($shipmentId !== null) {
                InboundShipment::query()
                    ->whereKey($shipmentId)
                    ->whereIn('status', ['expected', 'open', 'receiving'])
                    ->update(['status' => 'open']);
            }

            return $session->refresh();
        });

        $this->propagateScanFirstConfirmsToAsnSession->handle($session->fresh(), $openedBy);

        return $session->fresh();
    }

    /**
     * Prefer remaining expected parent EPCs on the shipment; fall back to document/union roots.
     * When expected lines exist, only return parents available to $forSessionId (unclaimed
     * or claimed by that session / stale claimer).
     *
     * @param  list<string>  $allowedStatuses
     * @return list<int>
     */
    private function resolveSessionParentEpcIds(
        EpcisDocument $document,
        ?InboundShipment $shipment,
        array $allowedStatuses,
        ?int $forSessionId = null,
    ): array {
        if ($shipment !== null && Schema::hasTable('inbound_expected_lines')) {
            $hasAnyExpectedLines = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipment->getKey())
                ->exists();

            if ($hasAnyExpectedLines) {
                return InboundExpectedLineClaims::availableExpectedParentEpcIds(
                    (int) $shipment->getKey(),
                    $forSessionId,
                );
            }

            return $this->resolveUnionRootParentEpcIds($shipment, $allowedStatuses);
        }

        return $this->resolveRootParentEpcIds($document);
    }

    /**
     * @param  list<string>  $allowedStatuses
     * @return list<int>
     */
    public function resolveUnionRootParentEpcIds(InboundShipment $shipment, array $allowedStatuses): array
    {
        return $this->resolveAsnRootParentEpcIds->resolveUnionRootParentEpcIds($shipment, $allowedStatuses);
    }

    /**
     * @return list<int>
     */
    public function resolveRootParentEpcIds(EpcisDocument $document): array
    {
        return $this->resolveAsnRootParentEpcIds->resolveRootParentEpcIds($document);
    }

    private function maybeUpdateOpenSessionSite(
        ReceivingSession $existing,
        int $resolvedSiteId,
        ?int $requestedSiteId,
    ): ReceivingSession {
        $existingSiteId = $existing->site_id !== null ? (int) $existing->site_id : null;
        $newSiteId = $resolvedSiteId;

        if ($existingSiteId === null && $newSiteId !== null) {
            $existing->forceFill(['site_id' => $newSiteId])->save();

            return $existing->refresh();
        }

        if (
            $requestedSiteId !== null
            && $existingSiteId !== null
            && $existingSiteId !== $newSiteId
        ) {
            $noConfirms = (int) $existing->confirmed_parent_count === 0
                && (int) $existing->confirmed_child_count === 0;

            if ($noConfirms) {
                $existing->forceFill(['site_id' => $newSiteId])->save();

                return $existing->refresh();
            }

            throw new DomainException(
                "Cannot reopen receiving at a different site: session #{$existing->getKey()} is already open at site {$existingSiteId}"
                .' with confirmed scans.',
            );
        }

        return $existing;
    }

    /**
     * @param  list<string>  $allowedStatuses
     */
    private function reopenCancelledInboundAsnSession(
        ReceivingSession $session,
        EpcisDocument $document,
        int $resolvedSiteId,
        array $allowedStatuses,
        ?int $resolvedPrincipalId = null,
    ): ReceivingSession {
        if ($session->receiving_events_generated_at !== null || $session->receiving_epcis_document_id !== null) {
            throw new DomainException('Cannot reopen receiving: session already has authored receiving EPCIS.');
        }

        $shipmentId = Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')
            && ($session->inbound_shipment_id ?? $document->inbound_shipment_id) !== null
            ? (int) ($session->inbound_shipment_id ?? $document->inbound_shipment_id)
            : null;

        $shipment = $shipmentId !== null
            ? InboundShipment::query()->find($shipmentId)
            : null;

        $rootParentIds = $this->resolveSessionParentEpcIds(
            $document,
            $shipment,
            $allowedStatuses,
            (int) $session->getKey(),
        );

        return DB::transaction(function () use (
            $session,
            $resolvedSiteId,
            $resolvedPrincipalId,
            $rootParentIds,
            $shipmentId,
        ): ReceivingSession {
            $session = ReceivingSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->status !== 'cancelled') {
                return $session;
            }

            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->delete();

            $updates = [
                'status' => 'open',
                'site_id' => $resolvedSiteId,
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'completed_at' => null,
            ];

            if ($shipmentId !== null && Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
                $updates['inbound_shipment_id'] = $shipmentId;
            }

            if (
                $resolvedPrincipalId !== null
                && TenantFeatures::forTenant(tenant())->supportsPrincipals()
            ) {
                $updates['principal_id'] = $resolvedPrincipalId;
            }

            if (Schema::hasColumn('receiving_sessions', 'cancelled_at')) {
                $updates['cancelled_at'] = null;
            }

            $session->forceFill($updates)->save();

            // Parallel on: reopen empty (claim on scan). Parallel off: seed remaining parents.
            $allowParallel = TenantSettings::forTenant(tenant())->allowParallelSessions();
            $seedOnOpen = ! ($allowParallel && $shipmentId !== null);

            if ($seedOnOpen) {
                $claimedParentIds = InboundExpectedLineClaims::claimExpectedParents($session, $rootParentIds);

                $now = now();
                $rows = [];
                foreach ($claimedParentIds as $epcId) {
                    $rows[] = [
                        'receiving_session_id' => $session->getKey(),
                        'epc_id' => $epcId,
                        'parent_epc_id' => null,
                        'line_role' => 'parent',
                        'status' => 'expected',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    ReceivingScanLine::query()->insert($rows);
                }

                $session->forceFill([
                    'expected_parent_count' => count($claimedParentIds),
                ])->save();
            }

            return $session->refresh();
        });
    }

    private function ensureSessionPrincipal(
        ReceivingSession $session,
        int $siteId,
        ?int $resolvedPrincipalId,
    ): ReceivingSession {
        if (! TenantFeatures::forTenant(tenant())->supportsPrincipals()) {
            return $session;
        }

        $current = $session->principal_id !== null ? (int) $session->principal_id : null;
        if ($current !== null && $current > 0) {
            return $session;
        }

        $principalId = $resolvedPrincipalId
            ?? PrincipalCustody::forTenant()->resolveSessionPrincipalId($siteId);

        if ($principalId === null) {
            return $session;
        }

        $session->forceFill(['principal_id' => $principalId])->save();

        return $session->refresh();
    }
}
