<?php

namespace App\Actions\Receiving;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Transferring\ConfirmTransferringReceiveScan;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Custody\UnreceivedPartnerShipment;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Receiving\FindOpenAsnSessionExpectingEpc;
use App\Support\Receiving\FindOpenTransferReceiveSessionExpectingEpc;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ResolveReceiveScanContext;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantSettings;
use App\Support\Transferring\RecomputeTransferReceivedCount;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ConfirmReceivingScan
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly ReceivingGate $receivingGate,
        private readonly CompleteReceivingSession $completeReceivingSession,
        private readonly ResolveReceiveScanContext $resolveReceiveScanContext,
        private readonly ConfirmTransferringReceiveScan $confirmTransferringReceiveScan,
        private readonly FindOpenAsnSessionExpectingEpc $findOpenAsnSessionExpectingEpc,
        private readonly FindOpenTransferReceiveSessionExpectingEpc $findOpenTransferReceiveSessionExpectingEpc,
        private readonly ConfirmExpectedScanLineOnSession $confirmExpectedScanLineOnSession,
        private readonly EpcOnAnotherOpenReceivingSession $epcOnAnotherOpenReceivingSession,
        private readonly SeedReceivingAsnParentChildren $seedReceivingAsnParentChildren,
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
        private readonly ResolveEpcLastKnownGln $resolveEpcLastKnownGln,
        private readonly CompensateTransferReceiveLine $compensateTransferReceiveLine,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     line: ?ReceivingScanLine,
     *     epc: ?Epc,
     *     effect: string,
     *     has_ti?: bool,
     *     matched_asn_document_id?: ?int,
     *     matched_transfer_session_id?: ?int,
     *     ti_warning?: ?string,
     *     session_completed?: bool,
     *     reconciled_asn_session_id?: ?int,
     *     reconciled_transfer_receive_session_id?: ?int
     * }
     */
    public function handle(
        ReceivingSession $session,
        string $scan,
        ?int $userId = null,
        bool $autoConfirmChildren = false,
        bool $unpack = false,
    ): array {
        $scan = ElementString::normalize($scan);
        $session = $session->fresh() ?? $session;

        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $actor = $this->resolveActor($userId);
        if ($actor !== null) {
            $this->assertCanAccessSessionSite($actor, $session);
        }

        if ($session->isScanFirst()) {
            return $this->confirmScanFirst($session, $scan, $userId, $autoConfirmChildren, $actor);
        }

        if ($session->isTransferReceive()) {
            return $this->confirmTransferReceive($session, $scan, $userId);
        }

        return $this->confirmInboundAsn($session, $scan, $userId, $autoConfirmChildren, $unpack);
    }

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     line: ?ReceivingScanLine,
     *     epc: ?Epc,
     *     effect: string,
     *     has_ti: bool,
     *     matched_asn_document_id: ?int,
     *     matched_transfer_session_id: ?int,
     *     ti_warning: ?string,
     *     reconciled_asn_session_id: ?int,
     *     reconciled_transfer_receive_session_id: ?int
     * }
     */
    private function confirmScanFirst(
        ReceivingSession $session,
        string $scan,
        ?int $userId,
        bool $autoConfirmChildren = false,
        ?User $actor = null,
    ): array {
        if ($session->site_id === null) {
            return [
                'ok' => false,
                'message' => 'Scan-first receive requires a site on this session.',
                'line' => null,
                'epc' => null,
                'effect' => 'no_receive_site',
                'has_ti' => false,
                'matched_asn_document_id' => null,
                'matched_transfer_session_id' => null,
                'ti_warning' => null,
                'reconciled_asn_session_id' => null,
            ];
        }

        $context = $this->resolveReceiveScanContext->handle($scan, $session);
        $epc = $context['epc'];

        if ($epc === null) {
            return [
                'ok' => false,
                'message' => 'Unknown barcode — EPC must exist from prior EPCIS/commission.',
                'line' => null,
                'epc' => null,
                'effect' => 'not_found',
                'has_ti' => false,
                'matched_asn_document_id' => null,
                'matched_transfer_session_id' => null,
                'ti_warning' => null,
                'reconciled_asn_session_id' => null,
            ];
        }

        $hasTi = (bool) $context['has_ti'];
        $tiWarning = null;

        if (! $hasTi) {
            if (TenantSettings::forTenant(tenant())->requireTiForScanFirst()) {
                return [
                    'ok' => false,
                    'message' => 'TI required for scan-first receive — no shipping or commissioning event on file for this EPC.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'ti_required',
                    'has_ti' => false,
                    'matched_asn_document_id' => $context['matched_inbound_document_id'],
                    'matched_transfer_session_id' => $context['in_transit_transferring_session_id'],
                    'ti_warning' => null,
                    'reconciled_asn_session_id' => null,
                ];
            }

            $tiWarning = 'TI missing — no shipping/commissioning event on file. Confirm allowed with warning.';
        }

        $alreadyConfirmedOnSession = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('epc_id', $epc->getKey())
            ->where('status', 'confirmed')
            ->exists();

        if (! $alreadyConfirmedOnSession) {
            $custodyBlock = $this->scanFirstReceiveCustodyBlock($session, $epc, $context, $hasTi, $tiWarning);
            if ($custodyBlock !== null) {
                return $custodyBlock;
            }
        }

        $mismatch = $context['ilmd_soft_mismatch'];
        $actor ??= $this->resolveActor($userId);

        try {
            $result = DB::transaction(function () use (
                $session,
                $scan,
                $userId,
                $epc,
                $mismatch,
                $hasTi,
                $tiWarning,
                $context,
                $autoConfirmChildren,
                $actor,
                $alreadyConfirmedOnSession,
            ): array {
                $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

                if (in_array($session->status, ['completed', 'cancelled'], true)) {
                    return [
                        'ok' => false,
                        'message' => 'This receiving session is already closed.',
                        'line' => null,
                        'epc' => $epc,
                        'effect' => 'not_in_session',
                        'has_ti' => $hasTi,
                        'matched_asn_document_id' => $context['matched_inbound_document_id'],
                        'matched_transfer_session_id' => $context['in_transit_transferring_session_id'],
                        'ti_warning' => $tiWarning,
                        'reconciled_asn_session_id' => null,
                    ];
                }

                $line = ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($line !== null && $line->status === 'confirmed') {
                    $transferReconcile = [
                        'session_id' => null,
                        'warning' => null,
                        'needs_completion' => false,
                    ];
                    if ($session->isScanFirst()) {
                        $transferReconcile = $this->reconcileScanFirstToTransferReceive(
                            $session,
                            $line,
                            $epc,
                            $userId,
                        );
                    }

                    return [
                        'ok' => true,
                        'message' => 'Already confirmed.',
                        'line' => $line,
                        'epc' => $epc,
                        'effect' => 'already_confirmed',
                        'has_ti' => $hasTi,
                        'matched_asn_document_id' => $context['matched_inbound_document_id'],
                        'matched_transfer_session_id' => $context['in_transit_transferring_session_id'],
                        'ti_warning' => $tiWarning,
                        'reconciled_asn_session_id' => null,
                        'reconciled_transfer_receive_session_id' => $transferReconcile['session_id'],
                        'transfer_reconcile_warning' => $transferReconcile['warning'],
                        'transfer_needs_completion' => $transferReconcile['needs_completion'],
                    ];
                }

                // Inside the transaction: a hold opened after the barcode resolved must still
                // block the line, and the session row is already locked against a racing scan.
                $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
                if ($hold !== null) {
                    $caseId = $hold->exception_id;
                    $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

                    return [
                        'ok' => false,
                        'message' => 'Under quarantine'.$suffix.'. Clear or release quarantine before receiving.',
                        'line' => null,
                        'epc' => $epc,
                        'effect' => 'quarantined',
                        'has_ti' => $hasTi,
                        'matched_asn_document_id' => $context['matched_inbound_document_id'],
                        'matched_transfer_session_id' => $context['in_transit_transferring_session_id'],
                        'ti_warning' => null,
                        'reconciled_asn_session_id' => null,
                    ];
                }

                if (! $alreadyConfirmedOnSession) {
                    $custodyBlock = $this->scanFirstReceiveCustodyBlock($session, $epc, $context, $hasTi, $tiWarning);
                    if ($custodyBlock !== null) {
                        return $custodyBlock;
                    }
                }

                if ($this->epcOnAnotherOpenReceivingSession->exists($epc, $session)) {
                    return [
                        'ok' => false,
                        'message' => 'Already confirmed on another open receive session.',
                        'line' => null,
                        'epc' => $epc,
                        'effect' => 'double_receive',
                        'has_ti' => $hasTi,
                        'matched_asn_document_id' => $context['matched_inbound_document_id'],
                        'matched_transfer_session_id' => $context['in_transit_transferring_session_id'],
                        'ti_warning' => $tiWarning,
                        'reconciled_asn_session_id' => null,
                    ];
                }

                $lineRole = $epc->epc_type === 'sscc' ? 'parent' : 'child';
                $now = now();

                if ($line === null) {
                    $line = ReceivingScanLine::query()->create([
                        'receiving_session_id' => $session->getKey(),
                        'epc_id' => $epc->getKey(),
                        'parent_epc_id' => null,
                        'line_role' => $lineRole,
                        'status' => 'confirmed',
                        'scan_raw' => $scan,
                        'confirmed_at' => $now,
                        'confirmed_by' => $userId,
                        'ilmd_mismatch_json' => $mismatch,
                    ]);

                    $session->forceFill([
                        'status' => 'in_progress',
                        'expected_parent_count' => (int) $session->expected_parent_count + ($lineRole === 'parent' ? 1 : 0),
                        'confirmed_parent_count' => (int) $session->confirmed_parent_count + ($lineRole === 'parent' ? 1 : 0),
                        'expected_child_count' => (int) $session->expected_child_count + ($lineRole === 'child' ? 1 : 0),
                        'confirmed_child_count' => (int) $session->confirmed_child_count + ($lineRole === 'child' ? 1 : 0),
                    ])->save();
                } else {
                    $line->forceFill([
                        'status' => 'confirmed',
                        'scan_raw' => $scan,
                        'confirmed_at' => $now,
                        'confirmed_by' => $userId,
                        'ilmd_mismatch_json' => $mismatch,
                    ])->save();

                    $session->forceFill([
                        'status' => $session->status === 'open' ? 'in_progress' : $session->status,
                        'confirmed_parent_count' => (int) $session->confirmed_parent_count + ($line->line_role === 'parent' ? 1 : 0),
                        'confirmed_child_count' => (int) $session->confirmed_child_count + ($line->line_role === 'child' ? 1 : 0),
                    ])->save();
                }

                $matchedAsnId = $context['matched_inbound_document_id'];
                if (
                    $session->matched_epcis_document_id === null
                    && $matchedAsnId !== null
                ) {
                    $session->forceFill([
                        'matched_epcis_document_id' => $matchedAsnId,
                    ])->save();
                }

                $confirmedChildren = 0;
                if ($lineRole === 'parent') {
                    $documentIdForChildren = $matchedAsnId ?? $session->matched_epcis_document_id;

                    $confirmedChildren = $this->seedAndConfirmChildrenForParent(
                        $session,
                        $epc,
                        $documentIdForChildren !== null ? (int) $documentIdForChildren : null,
                        $userId,
                        $autoConfirmChildren,
                        $now,
                    );
                }

                // Scan-first never auto-completes — operator must Complete manually.

                $reconciledAsnSessionId = null;
                $asnNeedsCompletion = false;
                $asnSession = $this->findOpenAsnSessionExpectingEpc->handle(
                    $epc,
                    $session,
                    $session->site_id,
                    $actor,
                );

                if ($asnSession !== null) {
                    $this->assertCanAccessReconciledSessionSite($actor, $asnSession);

                    $reconcile = $this->confirmExpectedScanLineOnSession->handle(
                        $asnSession,
                        $line->fresh(),
                        $userId,
                    );

                    if ($reconcile['ok']) {
                        $reconciledAsnSessionId = (int) $asnSession->getKey();
                        $asnNeedsCompletion = (bool) ($reconcile['needs_completion'] ?? false);
                    }
                }

                $transferReconcile = $this->reconcileScanFirstToTransferReceive(
                    $session,
                    $line->fresh(),
                    $epc,
                    $userId,
                );

                $message = 'Unit confirmed.';
                if ($lineRole === 'parent') {
                    $message = $confirmedChildren > 0
                        ? sprintf('Pallet confirmed · %d units', $confirmedChildren)
                        : 'Pallet confirmed.';
                }

                return [
                    'ok' => true,
                    'message' => $message,
                    'line' => $line->refresh(),
                    'epc' => $epc,
                    'effect' => $lineRole === 'parent' ? 'parent_confirmed' : 'child_confirmed',
                    'has_ti' => $hasTi,
                    'matched_asn_document_id' => $matchedAsnId,
                    'matched_transfer_session_id' => $context['in_transit_transferring_session_id'],
                    'ti_warning' => $tiWarning,
                    'reconciled_asn_session_id' => $reconciledAsnSessionId,
                    'reconciled_transfer_receive_session_id' => $transferReconcile['session_id'],
                    'transfer_reconcile_warning' => $transferReconcile['warning'],
                    'transfer_needs_completion' => $transferReconcile['needs_completion'],
                    'asn_needs_completion' => $asnNeedsCompletion,
                ];
            });
        } catch (DomainException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'line' => null,
                'epc' => $epc,
                'effect' => 'transfer_reconcile_failed',
                'has_ti' => $hasTi,
                'matched_asn_document_id' => $context['matched_inbound_document_id'],
                'matched_transfer_session_id' => $context['in_transit_transferring_session_id'],
                'ti_warning' => $tiWarning,
                'reconciled_asn_session_id' => null,
            ];
        }

        $asnNeedsCompletion = (bool) ($result['asn_needs_completion'] ?? false);
        $reconciledAsnSessionId = $result['reconciled_asn_session_id'] ?? null;
        $transferNeedsCompletion = (bool) ($result['transfer_needs_completion'] ?? false);
        $reconciledTransferReceiveSessionId = $result['reconciled_transfer_receive_session_id'] ?? null;
        unset($result['asn_needs_completion'], $result['transfer_needs_completion'], $result['transfer_reconcile_warning']);

        if ($asnNeedsCompletion && $reconciledAsnSessionId !== null) {
            try {
                $asnSession = ReceivingSession::query()->findOrFail($reconciledAsnSessionId);
                $this->assertCanAccessReconciledSessionSite($actor, $asnSession);
                $this->completeReceivingSession->handle($asnSession, $userId);
            } catch (Throwable $e) {
                $result['message'] = trim((string) $result['message'])
                    .' ASN scan saved, but receiving could not be completed: '.$e->getMessage();
            }
        }

        if ($transferNeedsCompletion && $reconciledTransferReceiveSessionId !== null) {
            try {
                $transferReceive = ReceivingSession::query()->findOrFail($reconciledTransferReceiveSessionId);
                if ($actor instanceof User) {
                    $this->assertCanAccessReconciledSessionSite($actor, $transferReceive);
                }
                $this->completeReceivingSession->handle($transferReceive->fresh(), $userId);
            } catch (Throwable $e) {
                $result['message'] = trim((string) $result['message'])
                    .' Transfer receive saved, but completion could not finish: '.$e->getMessage();
                $result['completion_error'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Scan-first receive must not claim stock that is on hand at another org site.
     * Unreceived partner inbound and intracompany transfer in transit to this dock
     * are still allowed.
     *
     * @param  array{
     *     matched_inbound_document_id: ?int,
     *     in_transit_transferring_session_id: ?int,
     * }  $context
     * @return array{
     *     ok: false,
     *     message: string,
     *     line: null,
     *     epc: Epc,
     *     effect: string,
     *     has_ti: bool,
     *     matched_asn_document_id: ?int,
     *     matched_transfer_session_id: ?int,
     *     ti_warning: ?string,
     *     reconciled_asn_session_id: null
     * }|null
     */
    private function scanFirstReceiveCustodyBlock(
        ReceivingSession $session,
        Epc $epc,
        array $context,
        bool $hasTi = false,
        ?string $tiWarning = null,
    ): ?array {
        $sessionSiteId = $session->site_id;
        if ($sessionSiteId === null) {
            return [
                'ok' => false,
                'message' => 'Scan-first receive requires a site on this session.',
                'line' => null,
                'epc' => $epc,
                'effect' => 'no_receive_site',
                'has_ti' => $hasTi,
                'matched_asn_document_id' => $context['matched_inbound_document_id'] ?? null,
                'matched_transfer_session_id' => $context['in_transit_transferring_session_id'] ?? null,
                'ti_warning' => $tiWarning,
                'reconciled_asn_session_id' => null,
            ];
        }

        $sessionSiteId = (int) $sessionSiteId;
        $epcId = (int) $epc->getKey();

        $transferId = $context['in_transit_transferring_session_id'] ?? null;
        if ($transferId !== null) {
            $transfer = TransferringSession::query()->find($transferId);
            if ($transfer !== null && (int) $transfer->to_site_id === $sessionSiteId) {
                return null;
            }
        }

        if (UnreceivedPartnerShipment::matches($this->resolveEpcLastKnownGln->latestEventMeta($epc))) {
            return null;
        }

        if ($this->shippableEpcsAtSite->contains($sessionSiteId, $epcId)) {
            return null;
        }

        foreach (SiteAccess::organizationFacilityQuery()->pluck('id') as $otherSiteId) {
            $otherSiteId = (int) $otherSiteId;
            if ($otherSiteId === $sessionSiteId) {
                continue;
            }

            if ($this->shippableEpcsAtSite->contains($otherSiteId, $epcId)) {
                return [
                    'ok' => false,
                    'message' => 'This unit is on hand at another site. Receive it there or transfer it first.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'not_at_receive_site',
                    'has_ti' => $hasTi,
                    'matched_asn_document_id' => $context['matched_inbound_document_id'] ?? null,
                    'matched_transfer_session_id' => $context['in_transit_transferring_session_id'] ?? null,
                    'ti_warning' => $tiWarning,
                    'reconciled_asn_session_id' => null,
                ];
            }
        }

        return null;
    }

    /**
     * Seed (and optionally auto-confirm) aggregation children under a scanned SSCC
     * for scan-first receives, using the matched inbound ASN document.
     *
     * @return int Newly confirmed child count for this parent
     */
    private function seedAndConfirmChildrenForParent(
        ReceivingSession $session,
        Epc $parentEpc,
        ?int $documentId,
        ?int $userId,
        bool $autoConfirmChildren,
        mixed $now,
    ): int {
        if ($documentId === null) {
            return 0;
        }

        $document = EpcisDocument::query()->find($documentId);

        $childEpcIds = AggregationLink::query()
            ->where('parent_epc_id', $parentEpc->getKey())
            ->whereNull('valid_to')
            ->whereIn('established_by_event_id', function ($query) use ($documentId, $document): void {
                $query->select('id')
                    ->from('epcis_events')
                    ->where('document_id', $documentId);

                if (
                    $document !== null
                    && Schema::hasColumn('epcis_events', 'ingest_generation')
                    && Schema::hasColumn('epcis_documents', 'ingest_generation')
                    && filled($document->getAttribute('ingest_generation'))
                ) {
                    $query->where('ingest_generation', $document->getAttribute('ingest_generation'));
                }
            })
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($childEpcIds === []) {
            return 0;
        }

        $confirmableChildIds = $childEpcIds;

        if ($autoConfirmChildren) {
            $quarantinedChildIds = QuarantineHold::query()
                ->open()
                ->whereIn('epc_id', $childEpcIds)
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $confirmableChildIds = array_values(array_diff($childEpcIds, $quarantinedChildIds));
        }

        $confirmableSet = array_fill_keys($confirmableChildIds, true);

        $alreadyConfirmedAmongChildren = 0;
        if ($autoConfirmChildren && $confirmableChildIds !== []) {
            $alreadyConfirmedAmongChildren = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->whereIn('epc_id', $confirmableChildIds)
                ->where('status', 'confirmed')
                ->count();
        }

        $childRows = [];
        foreach ($childEpcIds as $childEpcId) {
            $autoConfirm = $autoConfirmChildren && isset($confirmableSet[$childEpcId]);

            $childRows[] = [
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $childEpcId,
                'parent_epc_id' => $parentEpc->getKey(),
                'line_role' => 'child',
                'status' => $autoConfirm ? 'confirmed' : 'expected',
                'confirmed_at' => $autoConfirm ? $now : null,
                'confirmed_by' => $autoConfirm ? $userId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($childRows !== []) {
            ReceivingScanLine::query()->insertOrIgnore($childRows);
        }

        $confirmedChildren = 0;

        if ($autoConfirmChildren && $confirmableChildIds !== []) {
            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->whereIn('epc_id', $confirmableChildIds)
                ->where('status', 'expected')
                ->update([
                    'status' => 'confirmed',
                    'parent_epc_id' => $parentEpc->getKey(),
                    'confirmed_at' => $now,
                    'confirmed_by' => $userId,
                    'updated_at' => $now,
                ]);

            // Attach parent on prior scan-first unit confirms for these EPCs.
            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->whereNull('parent_epc_id')
                ->whereIn('epc_id', $confirmableChildIds)
                ->update([
                    'parent_epc_id' => $parentEpc->getKey(),
                    'updated_at' => $now,
                ]);

            $nowConfirmedAmongChildren = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->whereIn('epc_id', $confirmableChildIds)
                ->where('status', 'confirmed')
                ->count();

            $confirmedChildren = max(0, $nowConfirmedAmongChildren - $alreadyConfirmedAmongChildren);
        }

        $expectedChildCount = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('line_role', 'child')
            ->count();

        $session->forceFill([
            'expected_child_count' => $expectedChildCount,
            'confirmed_child_count' => (int) $session->confirmed_child_count + $confirmedChildren,
        ])->save();

        return $confirmedChildren;
    }

    /**
     * When floor confirms happen on scan-first, dual-write onto an open transfer_receive
     * at the same site (mirrors ASN reconcile) so transferring received_at is set.
     *
     * Dual-write transferring receive first, then confirm the receiving line — otherwise
     * an already_confirmed transfer_receive line can skip repairing a stuck transferring line.
     */
    /**
     * @return array{
     *     session_id: ?int,
     *     warning: ?string,
     *     needs_completion: bool
     * }
     */
    private function reconcileScanFirstToTransferReceive(
        ReceivingSession $scanFirst,
        ReceivingScanLine $sourceLine,
        Epc $epc,
        ?int $userId,
    ): array {
        $empty = [
            'session_id' => null,
            'warning' => null,
            'needs_completion' => false,
        ];

        $transferReceive = $this->findOpenTransferReceiveSessionExpectingEpc->handle(
            $epc,
            $scanFirst,
            $scanFirst->site_id,
        );

        if ($transferReceive === null || $transferReceive->transferring_session_id === null) {
            return $empty;
        }

        $actor = $this->resolveActor($userId);
        if ($actor instanceof User) {
            $this->assertCanAccessReconciledSessionSite($actor, $transferReceive);
        }

        $transfer = TransferringSession::query()->find($transferReceive->transferring_session_id);
        if ($transfer === null) {
            return $empty;
        }

        $scan = filled($sourceLine->scan_raw)
            ? (string) $sourceLine->scan_raw
            : (string) $epc->epc_uri;

        $transferResult = $this->confirmTransferringReceiveScan->handle(
            $transfer,
            $scan,
            $userId,
            generateReceiveEvents: false,
            markTransferCompleted: false,
        );

        if (! $transferResult['ok'] && $transferResult['effect'] !== 'already_received') {
            throw new DomainException((string) $transferResult['message']);
        }

        $reconcile = $this->confirmExpectedScanLineOnSession->handle(
            $transferReceive,
            $sourceLine,
            $userId,
        );

        if (! $reconcile['ok']) {
            $this->compensateTransferReceiveLine->handle($transfer, $epc);

            return [
                'session_id' => null,
                'warning' => filled($reconcile['message'] ?? null)
                    ? (string) $reconcile['message']
                    : 'Transfer receive line could not be confirmed.',
                'needs_completion' => false,
            ];
        }

        $remainingExpected = ReceivingScanLine::query()
            ->where('receiving_session_id', $transferReceive->getKey())
            ->where('status', 'expected')
            ->count();

        return [
            'session_id' => (int) $transferReceive->getKey(),
            'warning' => null,
            'needs_completion' => $remainingExpected === 0,
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     line: ?ReceivingScanLine,
     *     epc: ?Epc,
     *     effect: string,
     *     session_completed: bool
     * }
     */
    private function confirmTransferReceive(
        ReceivingSession $session,
        string $scan,
        ?int $userId,
    ): array {
        if ($session->transferring_session_id === null) {
            throw new DomainException('Transfer receive session has no linked transferring session.');
        }

        if (in_array($session->status, ['completed', 'cancelled'], true)) {
            return $this->transferReceiveClosedSessionResult(null);
        }

        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];
        $mismatch = $resolved['ilmd_soft_mismatch'];

        if ($epc === null) {
            return [
                'ok' => false,
                'message' => 'Barcode not recognized. Check the label and try again.',
                'line' => null,
                'epc' => null,
                'effect' => 'not_found',
                'session_completed' => false,
            ];
        }

        $line = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('epc_id', $epc->getKey())
            ->first();

        if ($line === null) {
            return [
                'ok' => false,
                'message' => 'This barcode is not expected on this transfer receive session.',
                'line' => null,
                'epc' => $epc,
                'effect' => 'not_in_session',
                'session_completed' => false,
            ];
        }

        if ($line->status !== 'confirmed' && $this->epcOnAnotherOpenReceivingSession->exists($epc, $session)) {
            $other = $this->epcOnAnotherOpenReceivingSession->otherSession($epc, $session);
            // Scan-first confirms at the destination are the intended source for
            // OpenTransferReceivingSession backfill — do not treat as double receive.
            if ($other === null || ! $other->isScanFirst()) {
                return [
                    'ok' => false,
                    'message' => 'Already confirmed on another open receive session.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'double_receive',
                    'session_completed' => false,
                ];
            }
        }

        $transfer = TransferringSession::query()->findOrFail($session->transferring_session_id);

        $transactionResult = DB::transaction(function () use (
            $session,
            $transfer,
            $line,
            $scan,
            $userId,
            $mismatch,
            $epc,
        ): array {
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $transfer = TransferringSession::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($session->status, ['completed', 'cancelled'], true)) {
                return [
                    'kind' => 'closed',
                    'session_completed' => false,
                ];
            }

            $line = ReceivingScanLine::query()->whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                $caseId = $hold->exception_id;
                $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

                return [
                    'kind' => 'quarantined',
                    'message' => 'Under quarantine'.$suffix.'. Clear or release quarantine before receiving.',
                    'session_completed' => false,
                ];
            }

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $transfer->getKey())
                ->where('epc_id', $epc->getKey())
                ->lockForUpdate()
                ->first();

            if ($line->status === 'confirmed') {
                $transferApply = $this->applyTransferReceiveLine($transfer, $transferLine, $epc, $userId);

                if (! $transferApply['ok'] && $transferApply['effect'] !== 'already_received') {
                    return [
                        'kind' => 'transfer_failed',
                        'message' => $transferApply['message'],
                        'effect' => $transferApply['effect'],
                        'line' => $line,
                        'session_completed' => false,
                    ];
                }

                return [
                    'kind' => 'already_confirmed',
                    'line' => $line,
                    'session_completed' => $session->status === 'completed'
                        || ($transferApply['session_completed'] ?? false),
                ];
            }

            $transferApply = $this->applyTransferReceiveLine($transfer, $transferLine, $epc, $userId);

            if (! $transferApply['ok'] && $transferApply['effect'] !== 'already_received') {
                return [
                    'kind' => 'transfer_failed',
                    'message' => $transferApply['message'],
                    'effect' => $transferApply['effect'],
                    'line' => $line,
                    'session_completed' => false,
                ];
            }

            $line->forceFill([
                'status' => 'confirmed',
                'scan_raw' => $scan,
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
                'ilmd_mismatch_json' => $mismatch,
            ])->save();

            $session->forceFill([
                'status' => $session->status === 'open' ? 'in_progress' : $session->status,
                'confirmed_parent_count' => (int) $session->confirmed_parent_count + ($line->line_role === 'parent' ? 1 : 0),
                'confirmed_child_count' => (int) $session->confirmed_child_count + ($line->line_role === 'child' ? 1 : 0),
            ])->save();

            $remainingExpected = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'expected')
                ->count();

            return [
                'kind' => 'confirmed',
                'line' => $line->refresh(),
                'session_completed' => $remainingExpected === 0,
            ];
        });

        if ($transactionResult['kind'] === 'closed') {
            return $this->transferReceiveClosedSessionResult($epc, $line);
        }

        if ($transactionResult['kind'] === 'quarantined') {
            return [
                'ok' => false,
                'message' => $transactionResult['message'],
                'line' => null,
                'epc' => $epc,
                'effect' => 'quarantined',
                'session_completed' => false,
            ];
        }

        if ($transactionResult['kind'] === 'transfer_failed') {
            return [
                'ok' => false,
                'message' => $transactionResult['message'],
                'line' => $transactionResult['line'],
                'epc' => $epc,
                'effect' => $transactionResult['effect'],
                'session_completed' => false,
            ];
        }

        if ($transactionResult['kind'] === 'already_confirmed') {
            return [
                'ok' => true,
                'message' => 'Already confirmed.',
                'line' => $transactionResult['line'],
                'epc' => $epc,
                'effect' => 'already_confirmed',
                'session_completed' => (bool) $transactionResult['session_completed'],
            ];
        }

        $sessionCompleted = (bool) $transactionResult['session_completed'];
        $line = $transactionResult['line'];

        $completionError = null;

        if ($sessionCompleted) {
            try {
                $this->completeReceivingSession->handle(
                    ReceivingSession::query()->findOrFail($session->getKey()),
                    $userId,
                );
            } catch (Throwable $e) {
                $sessionCompleted = false;
                $completionError = $e->getMessage();
                $this->completeReceivingSession->revertPostCommitIncompleteCompletion(
                    ReceivingSession::query()->findOrFail($session->getKey()),
                );
            }
        }

        $message = $sessionCompleted
            ? 'Received — transfer complete.'
            : 'Received at destination.';

        if ($completionError !== null) {
            $message = trim($message).' Scan saved, but receiving could not be completed: '.$completionError;
        }

        return [
            'ok' => true,
            'message' => $message,
            'line' => $line,
            'epc' => $epc,
            'effect' => $sessionCompleted ? 'completed' : 'child_confirmed',
            'session_completed' => $sessionCompleted,
            ...($completionError !== null ? ['completion_error' => $completionError] : []),
        ];
    }

    /**
     * Mark a transferring line received under an existing transfer session lock.
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     effect: string,
     *     session_completed: bool
     * }
     */
    private function applyTransferReceiveLine(
        TransferringSession $transfer,
        ?TransferringScanLine $transferLine,
        Epc $epc,
        ?int $userId,
    ): array {
        if ($transfer->status === 'completed') {
            return [
                'ok' => true,
                'message' => 'Transfer already received.',
                'effect' => 'completed',
                'session_completed' => true,
            ];
        }

        if ($transfer->status !== 'in_transit') {
            return [
                'ok' => false,
                'message' => 'Receive scans are only allowed while the transfer is in transit.',
                'effect' => 'session_not_in_transit',
                'session_completed' => false,
            ];
        }

        if ($transfer->transfer_events_generated_at === null || $transfer->transfer_epcis_document_id === null) {
            return [
                'ok' => false,
                'message' => 'Transfer ship EPCIS has not been authored yet — cannot receive.',
                'effect' => 'session_not_in_transit',
                'session_completed' => false,
            ];
        }

        if ($transferLine === null) {
            return [
                'ok' => false,
                'message' => 'This barcode is not on this transfer.',
                'effect' => 'not_on_transfer',
                'session_completed' => false,
            ];
        }

        if ($transferLine->status === 'received') {
            return [
                'ok' => true,
                'message' => 'Already received.',
                'effect' => 'already_received',
                'session_completed' => false,
            ];
        }

        $now = now();

        $transferLine->forceFill([
            'status' => 'received',
            'received_at' => $now,
            'received_by' => $userId,
        ])->save();

        $receivedCount = RecomputeTransferReceivedCount::forSession($transfer);
        $sessionCompleted = $receivedCount >= (int) $transfer->confirmed_count;

        $transfer->forceFill([
            'received_count' => $receivedCount,
        ])->save();

        return [
            'ok' => true,
            'message' => $sessionCompleted
                ? 'Received — transfer complete.'
                : 'Received at destination.',
            'effect' => $sessionCompleted ? 'completed' : 'received',
            'session_completed' => $sessionCompleted,
        ];
    }

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     line: ?ReceivingScanLine,
     *     epc: ?Epc,
     *     effect: 'parent_confirmed'|'child_confirmed'|'unexpected'|'already_confirmed'|'not_in_session'|'quarantined'
     * }
     */
    private function confirmInboundAsn(
        ReceivingSession $session,
        string $scan,
        ?int $userId,
        bool $autoConfirmChildren,
        bool $unpack = false,
    ): array {
        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];
        $mismatch = $resolved['ilmd_soft_mismatch'];

        if ($epc === null) {
            return [
                'ok' => false,
                'message' => 'Barcode not recognized. Check the label and try again.',
                'line' => null,
                'epc' => null,
                'effect' => 'unexpected',
            ];
        }

        $result = DB::transaction(function () use ($session, $scan, $userId, $autoConfirmChildren, $epc, $mismatch): array {
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($session->status, ['completed', 'cancelled'], true)) {
                return [
                    'ok' => false,
                    'message' => 'This receiving session is already closed.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'not_in_session',
                ];
            }

            if ($session->epcis_document_id !== null) {
                $document = EpcisDocument::query()->find($session->epcis_document_id);
                if ($document !== null) {
                    $blockingCase = $this->receivingGate->documentBlockedByOpenException($document);
                    if ($blockingCase !== null) {
                        $type = $blockingCase->type?->name ?? $blockingCase->type?->code ?? 'exception';

                        return [
                            'ok' => false,
                            'message' => "Cannot confirm receive: open document-wide exception #{$blockingCase->getKey()} ({$type}) blocks this file until resolved.",
                            'line' => null,
                            'epc' => $epc,
                            'effect' => 'document_blocked',
                        ];
                    }
                }
            }

            // Inside the transaction: a hold opened after the barcode resolved must still
            // block the line, and the session row is already locked against a racing scan.
            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                $caseId = $hold->exception_id;
                $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

                return [
                    'ok' => false,
                    'message' => 'Under quarantine'.$suffix.'. Clear or release quarantine before receiving.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'quarantined',
                ];
            }

            if ($this->epcOnAnotherOpenReceivingSession->exists($epc, $session)) {
                return [
                    'ok' => false,
                    'message' => 'Already confirmed on another open receive session.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'double_receive',
                ];
            }

            $line = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->lockForUpdate()
                ->first();

            $openToteRejection = $this->openToteScanRejection($session, $line, $epc);
            if ($openToteRejection !== null) {
                return $openToteRejection;
            }

            if ($line === null) {
                $line = ReceivingScanLine::query()->create([
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $epc->getKey(),
                    'parent_epc_id' => null,
                    'line_role' => $epc->epc_type === 'sscc' ? 'parent' : 'child',
                    'status' => 'unexpected',
                    'scan_raw' => $scan,
                    'ilmd_mismatch_json' => $mismatch,
                ]);

                return [
                    'ok' => false,
                    'message' => 'Barcode not on this ASN — logged as Unexpected.',
                    'line' => $line,
                    'epc' => $epc,
                    'effect' => 'unexpected',
                ];
            }

            if ($line->status === 'confirmed') {
                return [
                    'ok' => true,
                    'message' => 'Already confirmed.',
                    'line' => $line,
                    'epc' => $epc,
                    'effect' => 'already_confirmed',
                ];
            }

            if ($line->status === 'unexpected') {
                return [
                    'ok' => false,
                    'message' => 'Barcode not on this ASN — logged as Unexpected.',
                    'line' => $line,
                    'epc' => $epc,
                    'effect' => 'unexpected',
                ];
            }

            if ($line->line_role === 'parent') {
                return $this->confirmParent($session, $line, $epc, $scan, $userId, $mismatch, $autoConfirmChildren);
            }

            if ($line->line_role === 'child') {
                return $this->confirmChild($session, $line, $epc, $scan, $userId, $mismatch);
            }

            return [
                'ok' => false,
                'message' => 'EPC is not expected in this receiving session.',
                'line' => $line,
                'epc' => $epc,
                'effect' => 'not_in_session',
            ];
        });

        $needsCompletion = $result['needs_completion'] ?? false;
        unset($result['needs_completion']);

        if (! $needsCompletion) {
            return $result;
        }

        // The scan above already committed. Completion + EPCIS authoring run in their
        // own transaction outside this one, so a failure here (e.g. no SGLN on record)
        // cannot roll back the scan that was just confirmed — it only means the
        // session could not be marked complete, which is surfaced to the caller.
        try {
            $this->completeReceivingSession->handle(
                ReceivingSession::query()->findOrFail($session->getKey()),
                $userId,
                unpack: $unpack,
            );
        } catch (Throwable $e) {
            $result['message'] = trim((string) $result['message']).' Scan saved, but receiving could not be completed: '.$e->getMessage();
            $result['completion_error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $mismatch
     * @return array{ok: bool, message: string, line: ?ReceivingScanLine, epc: ?Epc, effect: string}
     */
    private function confirmParent(
        ReceivingSession $session,
        ReceivingScanLine $line,
        Epc $epc,
        string $scan,
        ?int $userId,
        ?array $mismatch,
        bool $autoConfirmChildren,
    ): array {
        $now = now();

        $line->forceFill([
            'status' => 'confirmed',
            'scan_raw' => $scan,
            'confirmed_at' => $now,
            'confirmed_by' => $userId,
            'ilmd_mismatch_json' => $mismatch,
        ])->save();

        $seeded = $this->seedReceivingAsnParentChildren->handle(
            $session,
            $epc,
            $userId,
            $autoConfirmChildren,
        );

        $confirmedChildren = $seeded['confirmed_children'];
        $skippedQuarantined = $seeded['skipped_quarantined'];

        $sessionUpdates = [
            'status' => 'in_progress',
            'confirmed_parent_count' => (int) $session->confirmed_parent_count + 1,
            'expected_child_count' => $seeded['expected_child_count'],
            'confirmed_child_count' => (int) $session->confirmed_child_count + $confirmedChildren,
        ];

        if (ReceivingPolicy::forTenant(tenant())->edgeMode() === ReceivingEdgeMode::OpenTote) {
            $sessionUpdates['active_parent_epc_id'] = $epc->getKey();
        }

        $session->forceFill($sessionUpdates)->save();

        $this->releaseOpenToteLockIfChildrenDone($session->refresh());
        $needsCompletion = $this->markSessionCompletedIfReady($session->refresh());

        $message = $confirmedChildren > 0
            ? sprintf('Pallet confirmed · %d units', $confirmedChildren)
            : 'Pallet confirmed.';

        if ($skippedQuarantined > 0) {
            $message .= sprintf(
                ' · %d quarantined unit(s) left expected (not auto-confirmed)',
                $skippedQuarantined,
            );
        }

        return [
            'ok' => true,
            'message' => $message,
            'line' => $line->refresh(),
            'epc' => $epc,
            'effect' => 'parent_confirmed',
            'needs_completion' => $needsCompletion,
            'skipped_quarantined_children' => $skippedQuarantined,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $mismatch
     * @return array{ok: bool, message: string, line: ?ReceivingScanLine, epc: ?Epc, effect: string}
     */
    private function confirmChild(
        ReceivingSession $session,
        ReceivingScanLine $line,
        Epc $epc,
        string $scan,
        ?int $userId,
        ?array $mismatch,
    ): array {
        if (! $this->isParentConfirmed($session, $line)) {
            return [
                'ok' => false,
                'message' => 'Confirm the pallet before scanning units.',
                'line' => $line,
                'epc' => $epc,
                'effect' => 'not_in_session',
            ];
        }

        $line->forceFill([
            'status' => 'confirmed',
            'scan_raw' => $scan,
            'confirmed_at' => now(),
            'confirmed_by' => $userId,
            'ilmd_mismatch_json' => $mismatch,
        ])->save();

        $session->forceFill([
            'status' => $session->status === 'open' ? 'in_progress' : $session->status,
            'confirmed_child_count' => (int) $session->confirmed_child_count + 1,
        ])->save();

        $this->releaseOpenToteLockIfChildrenDone($session->refresh());
        $needsCompletion = $this->markSessionCompletedIfReady($session->refresh());

        return [
            'ok' => true,
            'message' => 'Unit confirmed.',
            'needs_completion' => $needsCompletion,
            'line' => $line->refresh(),
            'epc' => $epc,
            'effect' => 'child_confirmed',
        ];
    }

    /**
     * @return array{ok: false, message: string, line: ?ReceivingScanLine, epc: Epc, effect: string}|null
     */
    private function openToteScanRejection(ReceivingSession $session, ?ReceivingScanLine $line, Epc $epc): ?array
    {
        if (ReceivingPolicy::forTenant(tenant())->edgeMode() !== ReceivingEdgeMode::OpenTote) {
            return null;
        }

        $activeParentId = $session->active_parent_epc_id;
        if ($activeParentId === null) {
            if ($line === null) {
                return null;
            }

            if ($line->line_role === 'parent' && $line->status === 'expected') {
                return null;
            }

            if (in_array($line->status, ['confirmed', 'unexpected'], true)) {
                return null;
            }

            return [
                'ok' => false,
                'message' => 'Scan an expected tote first.',
                'line' => $line,
                'epc' => $epc,
                'effect' => 'not_in_session',
            ];
        }

        $activeParentId = (int) $activeParentId;

        if ($line === null) {
            return null;
        }

        if (in_array($line->status, ['confirmed', 'unexpected'], true)) {
            return null;
        }

        if ($line->line_role === 'parent' && (int) $line->epc_id !== $activeParentId) {
            $lockedParent = Epc::query()->find($activeParentId);

            return [
                'ok' => false,
                'message' => 'Close tote '.$session->openToteLabel($lockedParent instanceof Epc ? $lockedParent : null).' first',
                'line' => $line,
                'epc' => $epc,
                'effect' => 'not_in_session',
            ];
        }

        if ($line->line_role === 'child' && (int) $line->parent_epc_id !== $activeParentId) {
            $mismatch = is_array($line->ilmd_mismatch_json) ? $line->ilmd_mismatch_json : [];
            $mismatch['comingling'] = [
                'scanned_while_parent_epc_id' => $activeParentId,
                'line_parent_epc_id' => (int) $line->parent_epc_id,
                'at' => now()->toIso8601String(),
            ];
            $line->forceFill(['ilmd_mismatch_json' => $mismatch])->save();

            return [
                'ok' => false,
                'message' => 'Unit belongs to another tote — not confirmed.',
                'line' => $line->fresh(),
                'epc' => $epc,
                'effect' => 'comingling',
            ];
        }

        return null;
    }

    private function isParentConfirmed(ReceivingSession $session, ReceivingScanLine $childLine): bool
    {
        if ($childLine->parent_epc_id === null) {
            return false;
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('epc_id', $childLine->parent_epc_id)
            ->where('line_role', 'parent')
            ->where('status', 'confirmed')
            ->exists();
    }

    /**
     * Open-tote lock used to clear only inside markSessionCompletedIfReady. With
     * explicit Complete receive, release the lock when the ASN is fully ready
     * (locked parent's children done and no other expected work). Keep the lock
     * while other totes remain so comingling scans are still rejected.
     */
    private function releaseOpenToteLockIfChildrenDone(ReceivingSession $session): void
    {
        if ($session->active_parent_epc_id === null) {
            return;
        }

        if ($session->openToteLockBlocksComplete()) {
            return;
        }

        if (! $session->isReadyToCompleteInboundAsn()) {
            return;
        }

        $session->forceFill([
            'active_parent_epc_id' => null,
        ])->save();
    }

    /**
     * Flip the session to completed when every expected line is in, but do not author
     * EPCIS here: this runs inside the scan-confirm transaction, and a completion
     * failure (e.g. no SGLN on record) must not roll back the scan that was just
     * confirmed. The caller runs CompleteReceivingSession after this transaction
     * commits when this returns true.
     */
    private function markSessionCompletedIfReady(ReceivingSession $session): bool
    {
        // Scan-first and transfer_receive complete only via explicit CompleteReceivingSession
        // (transfer_receive triggers that from confirmTransferReceive when all lines done).
        if ($session->isScanFirst() || $session->isTransferReceive()) {
            return false;
        }

        if (! TenantSettings::forTenant(tenant())->autoCompleteAsnOnReady()) {
            return false;
        }

        if (! $session->isReadyToCompleteInboundAsn()) {
            return false;
        }

        $session->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'active_parent_epc_id' => null,
        ])->save();

        return true;
    }

    /**
     * @return array{
     *     ok: false,
     *     message: string,
     *     line: ?ReceivingScanLine,
     *     epc: ?Epc,
     *     effect: 'not_in_session',
     *     session_completed: false
     * }
     */
    private function transferReceiveClosedSessionResult(?Epc $epc, ?ReceivingScanLine $line = null): array
    {
        return [
            'ok' => false,
            'message' => 'This receiving session is already closed.',
            'line' => $line,
            'epc' => $epc,
            'effect' => 'not_in_session',
            'session_completed' => false,
        ];
    }

    private function assertCanAccessReconciledSessionSite(?User $user, ReceivingSession $target): void
    {
        if ($user === null) {
            return;
        }

        $siteId = $target->site_id;
        if ($siteId === null) {
            if (! $user->can(Permissions::SitesAccessAll)) {
                throw new AuthorizationException('You do not have access to this receiving session site.');
            }

            return;
        }

        SiteAccess::assertCanAccessSite($user, (int) $siteId);
    }

    private function assertCanAccessSessionSite(User $user, ReceivingSession $session): void
    {
        if ($session->site_id === null) {
            if (! $user->can(Permissions::SitesAccessAll)) {
                throw new AuthorizationException('You do not have access to this receiving session.');
            }

            return;
        }

        SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
    }

    private function resolveActor(?int $userId): ?User
    {
        $user = auth()->user();
        if ($user instanceof User) {
            return $user;
        }

        if ($userId === null) {
            return null;
        }

        $resolved = User::query()->find($userId);

        return $resolved instanceof User ? $resolved : null;
    }
}
