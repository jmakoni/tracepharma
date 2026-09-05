<?php

namespace App\Actions\Receiving;

use App\Actions\Epcis\RecordAtpSoftWarning;
use App\Actions\Epcis\RecordScheduledProductMissingDea;
use App\Actions\Epcis\RecordSbdhOwningPartyMismatch;
use App\Enums\ReceivingSessionKind;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\ResolveReceivingSite;
use DomainException;
use Illuminate\Database\Query\Builder;
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
    ) {}

    public function handle(EpcisDocument $document, ?int $siteId = null, ?int $openedBy = null): ReceivingSession
    {
        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $allowed = $requireValidated ? ['validated'] : ['parsed', 'validated'];

        if (! in_array($document->status, $allowed, true)) {
            throw new InvalidArgumentException(
                $requireValidated
                    ? "Receiving session requires document status validated; got [{$document->status}]."
                    : "Receiving session requires document status parsed or validated; got [{$document->status}].",
            );
        }

        if (config('tracepharma.epcis.enforce_ts_for_receiving') && ! (bool) $document->dscsa_affirm) {
            throw new DomainException(
                'Cannot open receiving: document lacks DSCSA transaction statement affirmation (TS).',
            );
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

        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $resolvedSiteId = $this->resolveReceivingSite->handle($document, $siteId);

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, $resolvedSiteId);
        }

        if (
            Schema::hasColumn('epcis_documents', 'inbound_shipment_id')
            && $document->inbound_shipment_id === null
            && filled($document->asn_number)
            && (string) ($document->direction ?? '') === 'inbound'
        ) {
            $this->attachInboundDocumentToShipment->handle($document);
            $document = $document->refresh();
        }

        $shipmentId = Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')
            && $document->inbound_shipment_id !== null
            ? (int) $document->inbound_shipment_id
            : null;

        if ($shipmentId !== null) {
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

                $rootParentIds = $this->resolveUnionRootParentEpcIds(
                    InboundShipment::query()->findOrFail($shipmentId),
                    $allowed,
                );
                $this->expandExpectedParents->handle($shipmentSession, $rootParentIds);

                if (in_array($shipmentSession->status, ['open', 'in_progress'], true)) {
                    $this->propagateScanFirstConfirmsToAsnSession->handle($shipmentSession->fresh(), $openedBy);
                }

                return $shipmentSession->fresh();
            }
        }

        $existing = ReceivingSession::query()
            ->where('epcis_document_id', $document->getKey())
            ->first();

        if ($existing !== null) {
            if ($existing->status === 'cancelled') {
                $existing = $this->reopenCancelledInboundAsnSession(
                    $existing,
                    $document,
                    $resolvedSiteId,
                    $allowed,
                );
            } elseif (in_array($existing->status, ['open', 'in_progress'], true)) {
                $existing = $this->maybeUpdateOpenSessionSite(
                    $existing,
                    $resolvedSiteId,
                    $siteId,
                );

                if ($shipmentId !== null) {
                    $rootParentIds = $this->resolveUnionRootParentEpcIds(
                        InboundShipment::query()->findOrFail($shipmentId),
                        $allowed,
                    );
                    $this->expandExpectedParents->handle($existing, $rootParentIds);
                }
            }

            if (in_array($existing->status, ['open', 'in_progress'], true)) {
                $this->propagateScanFirstConfirmsToAsnSession->handle($existing->fresh(), $openedBy);
            }

            return $existing->fresh();
        }

        $rootParentIds = $shipmentId !== null
            ? $this->resolveUnionRootParentEpcIds(
                InboundShipment::query()->findOrFail($shipmentId),
                $allowed,
            )
            : $this->resolveRootParentEpcIds($document);

        $session = DB::transaction(function () use ($document, $resolvedSiteId, $openedBy, $rootParentIds, $shipmentId): ReceivingSession {
            $attributes = [
                'session_kind' => ReceivingSessionKind::InboundAsn,
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => $resolvedSiteId,
                'status' => 'open',
                'expected_parent_count' => count($rootParentIds),
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_by' => $openedBy,
                'opened_at' => now(),
            ];

            if ($shipmentId !== null) {
                $attributes['inbound_shipment_id'] = $shipmentId;
            }

            $session = ReceivingSession::query()->create($attributes);

            $now = now();
            $rows = [];
            foreach ($rootParentIds as $epcId) {
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

            if ($shipmentId !== null) {
                InboundShipment::query()
                    ->whereKey($shipmentId)
                    ->where('status', 'open')
                    ->update(['status' => 'receiving']);
            }

            return $session->refresh();
        });

        $this->propagateScanFirstConfirmsToAsnSession->handle($session->fresh(), $openedBy);

        return $session->fresh();
    }

    /**
     * Union of root parents across all shipment member documents in receiving-allowed status.
     *
     * @param  list<string>  $allowedStatuses
     * @return list<int>
     */
    public function resolveUnionRootParentEpcIds(InboundShipment $shipment, array $allowedStatuses): array
    {
        $documents = EpcisDocument::query()
            ->where('inbound_shipment_id', $shipment->getKey())
            ->whereIn('status', $allowedStatuses)
            ->orderBy('id')
            ->get();

        $ids = [];
        foreach ($documents as $document) {
            foreach ($this->resolveRootParentEpcIds($document) as $epcId) {
                $ids[$epcId] = true;
            }
        }

        $rootIds = array_map('intval', array_keys($ids));
        sort($rootIds);

        return $rootIds;
    }

    /**
     * Root parents: aggregation_links established by this document whose parent is not a child
     * of another link on the same document. Prefer SSCC parents when any exist.
     *
     * @return list<int>
     */
    public function resolveRootParentEpcIds(EpcisDocument $document): array
    {
        $linkQuery = AggregationLink::query()
            ->whereNull('valid_to')
            ->whereIn('established_by_event_id', $this->documentEventIdsSubquery($document));

        $parentIds = (clone $linkQuery)
            ->distinct()
            ->pluck('parent_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($parentIds === []) {
            return [];
        }

        $childIds = (clone $linkQuery)
            ->whereIn('child_epc_id', $parentIds)
            ->distinct()
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $childIdSet = array_fill_keys($childIds, true);
        $rootIds = array_values(array_filter(
            $parentIds,
            fn (int $id): bool => ! isset($childIdSet[$id]),
        ));

        if ($rootIds === []) {
            return [];
        }

        $ssccRootIds = Epc::query()
            ->whereIn('id', $rootIds)
            ->where('epc_type', 'sscc')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ssccRootIds !== []) {
            return $ssccRootIds;
        }

        sort($rootIds);

        return $rootIds;
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
    ): ReceivingSession {
        if ($session->receiving_events_generated_at !== null || $session->receiving_epcis_document_id !== null) {
            throw new DomainException('Cannot reopen receiving: session already has authored receiving EPCIS.');
        }

        $shipmentId = Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')
            && ($session->inbound_shipment_id ?? $document->inbound_shipment_id) !== null
            ? (int) ($session->inbound_shipment_id ?? $document->inbound_shipment_id)
            : null;

        $rootParentIds = $shipmentId !== null
            ? $this->resolveUnionRootParentEpcIds(
                InboundShipment::query()->findOrFail($shipmentId),
                $allowedStatuses,
            )
            : $this->resolveRootParentEpcIds($document);

        return DB::transaction(function () use ($session, $resolvedSiteId, $rootParentIds, $shipmentId): ReceivingSession {
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

            $now = now();
            $rows = [];

            foreach ($rootParentIds as $epcId) {
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

            $updates = [
                'status' => 'open',
                'site_id' => $resolvedSiteId,
                'expected_parent_count' => count($rootParentIds),
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'completed_at' => null,
            ];

            if ($shipmentId !== null && Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
                $updates['inbound_shipment_id'] = $shipmentId;
            }

            if (Schema::hasColumn('receiving_sessions', 'cancelled_at')) {
                $updates['cancelled_at'] = null;
            }

            $session->forceFill($updates)->save();

            return $session->refresh();
        });
    }

    /**
     * @return \Closure(Builder): void
     */
    private function documentEventIdsSubquery(EpcisDocument $document): \Closure
    {
        return function ($query) use ($document): void {
            $query->select('id')
                ->from('epcis_events')
                ->where('document_id', $document->getKey());

            if (
                Schema::hasColumn('epcis_events', 'ingest_generation')
                && Schema::hasColumn('epcis_documents', 'ingest_generation')
                && filled($document->getAttribute('ingest_generation'))
            ) {
                $query->where('ingest_generation', $document->getAttribute('ingest_generation'));
            }
        };
    }
}
