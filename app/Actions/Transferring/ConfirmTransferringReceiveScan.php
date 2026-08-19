<?php

namespace App\Actions\Transferring;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\Epc;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use App\Support\Transferring\RecomputeTransferReceivedCount;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Destination receive verification: re-scan a previously confirmed transfer EPC
 * while the session is in_transit. Completes the session when all lines are received.
 */
final class ConfirmTransferringReceiveScan
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly GenerateTransferringReceiveEpcisEvents $generateTransferringReceiveEpcisEvents,
        private readonly ReceivingGate $receivingGate,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     line: ?TransferringScanLine,
     *     epc: ?Epc,
     *     effect: 'received'|'already_received'|'not_on_transfer'|'not_found'|'session_not_in_transit'|'completed'|'quarantined',
     *     session_completed: bool
     * }
     */
    public function handle(
        TransferringSession $session,
        string $scan,
        ?int $userId = null,
        bool $generateReceiveEvents = false,
        bool $markTransferCompleted = true,
    ): array {
        if (! TenantFeatures::forTenant(tenant())->supportsTransferring()) {
            throw new DomainException('Transferring is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, (int) $session->to_site_id);
        }

        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];

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

        $result = DB::transaction(function () use ($session, $userId, $epc, $markTransferCompleted): array {
            $session = TransferringSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status === 'completed') {
                return [
                    'ok' => true,
                    'message' => 'Transfer already received.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'completed',
                    'session_completed' => true,
                    'should_generate_receive' => false,
                ];
            }

            if ($session->status !== 'in_transit') {
                return [
                    'ok' => false,
                    'message' => 'Receive scans are only allowed while the transfer is in transit.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'session_not_in_transit',
                    'session_completed' => false,
                    'should_generate_receive' => false,
                ];
            }

            // Receive must not close a transfer that never authored its shipping document.
            if ($session->transfer_events_generated_at === null || $session->transfer_epcis_document_id === null) {
                return [
                    'ok' => false,
                    'message' => 'Transfer ship EPCIS has not been authored yet — cannot receive.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'session_not_in_transit',
                    'session_completed' => false,
                    'should_generate_receive' => false,
                ];
            }

            $line = TransferringScanLine::query()
                ->where('transferring_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->lockForUpdate()
                ->first();

            if ($line === null) {
                return [
                    'ok' => false,
                    'message' => 'This barcode is not on this transfer.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'not_on_transfer',
                    'session_completed' => false,
                    'should_generate_receive' => false,
                ];
            }

            if ($line->status === 'received') {
                return [
                    'ok' => true,
                    'message' => 'Already received.',
                    'line' => $line,
                    'epc' => $epc,
                    'effect' => 'already_received',
                    'session_completed' => false,
                    'should_generate_receive' => false,
                ];
            }

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
                    'session_completed' => false,
                    'should_generate_receive' => false,
                ];
            }

            $now = now();

            $line->forceFill([
                'status' => 'received',
                'received_at' => $now,
                'received_by' => $userId,
            ])->save();

            $receivedCount = RecomputeTransferReceivedCount::forSession($session);
            $sessionCompleted = $receivedCount >= (int) $session->confirmed_count;

            $session->forceFill([
                'received_count' => $receivedCount,
                ...($sessionCompleted && $markTransferCompleted ? [
                    'status' => 'completed',
                    'received_at' => $now,
                    'completed_at' => $now,
                ] : []),
            ])->save();

            return [
                'ok' => true,
                'message' => $sessionCompleted
                    ? 'Received — transfer complete.'
                    : 'Received at destination.',
                'line' => $line->refresh(),
                'epc' => $epc,
                'effect' => $sessionCompleted ? 'completed' : 'received',
                'session_completed' => $sessionCompleted,
                'should_generate_receive' => $sessionCompleted,
            ];
        });

        // Receiving-session orchestration may defer emission to CompleteReceivingSession.
        if ($generateReceiveEvents && ($result['should_generate_receive'] ?? false)) {
            $this->generateTransferringReceiveEpcisEvents->handle(
                TransferringSession::query()->findOrFail($session->getKey()),
                $userId,
            );
        }

        unset($result['should_generate_receive']);

        return $result;
    }
}
