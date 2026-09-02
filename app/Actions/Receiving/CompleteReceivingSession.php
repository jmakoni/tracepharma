<?php

namespace App\Actions\Receiving;

use App\Actions\Transferring\GenerateTransferringReceiveEpcisEvents;
use App\Jobs\Receiving\NotifyWmsReceiveConfirm;
use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Custody\OutboundShipmentInTransit;
use App\Support\Logging\RedactsUrls;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Receiving\ResolveReceiveScanContext;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public orchestrator for completing a receiving session and emitting EPCIS events.
 *
 * - inbound_asn: session is typically already completed by ConfirmReceivingScan::markSessionCompletedIfReady,
 *   which defers this call to run after the scan-confirm transaction commits
 * - scan_first: manual complete — marks completed when confirmed lines exist, then GenerateReceivingEpcisEvents
 * - transfer_receive: completes linked transfer + GenerateTransferringReceiveEpcisEvents
 *
 * If receiving EPCIS authoring fails (e.g. SGLN cannot be built), the session is
 * reverted from completed → open so operators are not stuck with a "done" session
 * that has no custody events.
 */
final class CompleteReceivingSession
{
    public function __construct(
        private readonly GenerateReceivingEpcisEvents $generateReceivingEpcisEvents,
        private readonly GenerateTransferringReceiveEpcisEvents $generateTransferringReceiveEpcisEvents,
        private readonly ReceivingGate $receivingGate,
        private readonly ResolveReceiveScanContext $resolveReceiveScanContext,
    ) {}

    public function handle(
        ReceivingSession $session,
        ?int $actorId = null,
        bool $unpack = false,
        bool $shortClose = false,
    ): ReceivingSession {
        $session = $session->fresh() ?? $session;

        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User && $session->site_id !== null) {
            SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
        }

        try {
            $this->assertDocumentNotBlockedByOpenException($session);
            $this->assertScanFirstTiWhenRequired($session);
            if (! $shortClose) {
                $this->assertOpenToteMayComplete($session);
            }
        } catch (DomainException $e) {
            if (
                $session->status === 'completed'
                && $session->receiving_events_generated_at === null
                && $session->isInboundAsn()
            ) {
                $this->revertIncompleteCompletion($session);
            }

            throw $e;
        }

        if ($session->isTransferReceive()) {
            return $this->completeTransferReceive($session, $actorId, $shortClose);
        }

        if ($session->status !== 'completed' && ($session->isScanFirst() || ($shortClose && $session->isInboundAsn()))) {
            // Scan-first always marks complete here. Inbound ASN uses the same
            // path only for Scan In short-close (confirmed cases only). HUD
            // complete leaves shortClose false and still requires a full tote.
            // Locked, mirroring the transfer-receive branch: a concurrent complete
            // call (e.g. the last confirm auto-triggering completion while the
            // operator also taps "Complete") must not race this status transition.
            $session = DB::transaction(function () use ($session): ReceivingSession {
                $locked = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status === 'completed') {
                    return $locked;
                }

                $hasConfirmed = ReceivingScanLine::query()
                    ->where('receiving_session_id', $locked->getKey())
                    ->where('status', 'confirmed')
                    ->exists();

                if (! $hasConfirmed) {
                    return $locked;
                }

                $locked->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                ])->save();

                return $locked->refresh();
            });
        }

        if ($session->status !== 'completed') {
            return $session;
        }

        try {
            $generated = $this->generateReceivingEpcisEvents->handle($session, $actorId, $unpack);
        } catch (Throwable $e) {
            $this->revertIncompleteCompletion($session);

            throw $e;
        }

        if ($generated['generated']) {
            $this->dispatchWmsReceiveConfirm($session->refresh());
        }

        return $session->refresh();
    }

    /**
     * Optional WMS receive-confirm. Fail-soft: never revert a completed session.
     */
    private function dispatchWmsReceiveConfirm(ReceivingSession $session): void
    {
        if ($session->isTransferReceive()) {
            return;
        }

        $settings = TenantSettings::forTenant(tenant());
        if ($settings->wmsWebhooksKilled()) {
            return;
        }

        $endpoint = $settings->wmsReceiveConfirmUrl();
        if (blank($endpoint)) {
            return;
        }

        $tenant = tenant();
        if ($tenant === null) {
            return;
        }

        try {
            NotifyWmsReceiveConfirm::dispatch((string) $tenant->getKey(), (int) $session->getKey());
        } catch (Throwable $e) {
            Log::warning('WMS receive-confirm dispatch failed.', [
                'tenant_id' => (string) $tenant->getKey(),
                'session_id' => (int) $session->getKey(),
                'endpoint' => RedactsUrls::redactUrl($endpoint),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function assertDocumentNotBlockedByOpenException(ReceivingSession $session): void
    {
        if ($session->epcis_document_id === null) {
            return;
        }

        $document = $session->document ?? $session->document()->first();
        if ($document === null) {
            return;
        }

        $blockingCase = $this->receivingGate->documentBlockedByOpenException($document);
        if ($blockingCase === null) {
            return;
        }

        $type = $blockingCase->type?->name ?? $blockingCase->type?->code ?? 'exception';

        throw new DomainException(
            "Cannot complete receiving: open document-wide exception #{$blockingCase->getKey()} ({$type}) blocks this file until resolved.",
        );
    }

    private function assertOpenToteMayComplete(ReceivingSession $session): void
    {
        if (! $session->isInboundAsn()) {
            return;
        }

        if ($session->openToteLockBlocksComplete()) {
            $label = $session->openToteLabel();

            throw new DomainException(
                filled($label)
                    ? 'Close tote '.$label.' first before completing.'
                    : 'Close tote first before completing.',
            );
        }

        if ($session->hasUnclosedExpectedChildrenOfConfirmedParents()) {
            throw new DomainException(
                'Close tote or record shortage — expected units remain on a confirmed tote.',
            );
        }
    }

    private function assertScanFirstTiWhenRequired(ReceivingSession $session): void
    {
        if (! $session->isScanFirst()) {
            return;
        }

        if (! TenantSettings::forTenant(tenant())->requireTiForScanFirst()) {
            return;
        }

        $lines = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->whereNotNull('epc_id')
            ->with('epc')
            ->get();

        foreach ($lines as $line) {
            $epc = $line->epc;
            if ($epc === null) {
                $epc = Epc::query()->find($line->epc_id);
            }

            if ($epc === null) {
                continue;
            }

            if (! $this->resolveReceiveScanContext->epcHasTransactionInformation($epc)) {
                throw new DomainException(
                    'TI required for scan-first receive — one or more confirmed EPCs have no shipping or commissioning event on file.',
                );
            }
        }
    }

    /**
     * Complete the destination receive and close out the transfer.
     *
     * The transfer's received_count is recomputed from the lines that were actually
     * receive-scanned, never raised to match what was shipped: a transfer closed
     * with unscanned lines is a shortfall, and reporting it as fully received would
     * both hide the discrepancy and imply custody landed at the destination.
     *
     * Unreceived lines keep their `confirmed` status and get no receiving event, so
     * their latest event stays the transfer's shipping/in_transit one: those units
     * read as in transit ({@see OutboundShipmentInTransit}) and are shippable at
     * neither site, which is where goods that never arrived belong. If they turn up
     * later, receiving them at the site that has them restores custody there.
     */
    private function completeTransferReceive(
        ReceivingSession $session,
        ?int $actorId,
        bool $shortClose = false,
    ): ReceivingSession {
        if ($session->transferring_session_id === null) {
            throw new DomainException('Transfer receive session has no linked transferring session.');
        }

        $completion = DB::transaction(function () use ($session, $shortClose): array {
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $transfer = TransferringSession::query()
                ->whereKey($session->transferring_session_id)
                ->lockForUpdate()
                ->firstOrFail();

            $expected = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'expected')
                ->count();

            if ($expected > 0 && $session->status !== 'completed' && ! $shortClose) {
                // Still open lines — never emit transfer-receive EPCIS until the
                // receiving session is explicitly completed (including short-close).
                return [
                    'session' => $session->refresh(),
                    'transfer' => $transfer,
                    'should_generate' => false,
                    'received_count' => 0,
                ];
            }

            $now = now();

            if ($session->status !== 'completed') {
                $session->forceFill([
                    'status' => 'completed',
                    'completed_at' => $now,
                ])->save();
            }

            $receivedCount = TransferringScanLine::query()
                ->where('transferring_session_id', $transfer->getKey())
                ->where('status', 'received')
                ->count();

            $transferUpdates = ['received_count' => $receivedCount];

            if ($transfer->status !== 'completed') {
                $transferUpdates = array_merge($transferUpdates, [
                    'status' => 'completed',
                    'received_at' => $transfer->received_at ?? $now,
                    'completed_at' => $transfer->completed_at ?? $now,
                ]);
            }

            $transfer->forceFill($transferUpdates)->save();

            return [
                'session' => $session->refresh(),
                'transfer' => $transfer->refresh(),
                'should_generate' => true,
                'received_count' => $receivedCount,
            ];
        });

        /** @var ReceivingSession $session */
        $session = $completion['session'];

        /** @var TransferringSession $transfer */
        $transfer = $completion['transfer'];

        // Nothing scanned in means there is nothing to attest to: authoring a
        // receiving event with an empty epcList would claim a receipt that
        // did not happen.
        if ($completion['should_generate'] && $completion['received_count'] > 0) {
            try {
                $this->generateTransferringReceiveEpcisEvents->handle($transfer->fresh(), $actorId);
            } catch (Throwable $e) {
                $this->revertIncompleteTransferReceive($session, $transfer);

                $transfer = $transfer->fresh() ?? $transfer;
                if ($transfer->receive_events_generated_at !== null) {
                    throw $e instanceof DomainException
                        ? $e
                        : new DomainException($e->getMessage(), 0, $e);
                }

                throw new DomainException(
                    'Transfer receive not closed — receiving EPCIS could not be authored: '.$e->getMessage(),
                    0,
                    $e,
                );
            }

            $this->markTransferReceiveSessionEventsGenerated($session, $transfer->fresh() ?? $transfer);
        }

        return $session->refresh();
    }

    /**
     * Transfer-receive custody is authored on the linked transferring session's
     * document; mirror that timestamp onto the receive session so
     * {@see EpcOnAnotherOpenReceivingSession} releases
     * confirmed EPCs for ship/transfer/disposition.
     */
    private function markTransferReceiveSessionEventsGenerated(
        ReceivingSession $session,
        TransferringSession $transfer,
    ): void {
        if ($session->receiving_events_generated_at !== null || $transfer->receive_events_generated_at === null) {
            return;
        }

        $session->forceFill([
            'receiving_events_generated_at' => $transfer->receive_events_generated_at,
            'receiving_epcis_document_id' => $transfer->transfer_epcis_document_id,
        ])->save();
    }

    /**
     * Roll back transfer-receive completion when custody EPCIS was not authored.
     *
     * Scans and received marks stay in place; only the completed status is cleared
     * so the operator can retry after fixing the authoring blocker.
     */
    private function revertIncompleteTransferReceive(
        ReceivingSession $session,
        TransferringSession $transfer,
    ): void {
        DB::transaction(function () use ($session, $transfer): void {
            $transfer = TransferringSession::query()
                ->whereKey($transfer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->receive_events_generated_at !== null) {
                return;
            }

            if ($transfer->status === 'completed') {
                $transfer->forceFill([
                    'status' => 'in_transit',
                    'completed_at' => null,
                ])->save();
            }

            $lockedSession = ReceivingSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->revertIncompleteCompletion($lockedSession);
        });
    }

    /**
     * Roll back post-commit completion when custody EPCIS could not be authored.
     * Used by {@see ConfirmReceivingScan} after the scan transaction commits.
     */
    public function revertPostCommitIncompleteCompletion(ReceivingSession $session): void
    {
        $session = $session->fresh() ?? $session;

        if ($session->isTransferReceive() && $session->transferring_session_id !== null) {
            $transfer = TransferringSession::query()->find($session->transferring_session_id);
            if ($transfer !== null) {
                $this->revertIncompleteTransferReceive($session, $transfer);

                return;
            }
        }

        $this->revertIncompleteCompletion($session);
    }

    /**
     * Roll back a completed status when custody EPCIS was not authored.
     */
    private function revertIncompleteCompletion(ReceivingSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $locked = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->receiving_events_generated_at !== null) {
                return;
            }

            if ($locked->status !== 'completed') {
                return;
            }

            $hasConfirmed = ReceivingScanLine::query()
                ->where('receiving_session_id', $locked->getKey())
                ->where('status', 'confirmed')
                ->exists();

            $locked->forceFill([
                'status' => $hasConfirmed ? 'in_progress' : 'open',
                'completed_at' => null,
            ])->save();
        });
    }
}
