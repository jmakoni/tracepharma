<?php

namespace App\Actions\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Open (or return) a transfer_receive session seeded from an in-transit transfer's confirmed lines.
 */
final class OpenTransferReceivingSession
{
    public function __construct(
        private readonly PropagateScanFirstConfirmsToTransferReceiveSession $propagateScanFirstConfirmsToTransferReceiveSession,
        private readonly RevertTransferReceiveReceivingMarks $revertTransferReceiveReceivingMarks,
    ) {}

    public function handle(TransferringSession $transfer, ?int $openedBy = null): ReceivingSession
    {
        if (! TenantFeatures::forTenant(tenant())->supportsReceiving()) {
            throw new DomainException('Receiving is not available for this tenant profile.');
        }

        if ($transfer->transfer_events_generated_at === null || $transfer->transfer_epcis_document_id === null) {
            throw new InvalidArgumentException(
                'Transfer receive requires ship EPCIS to have been authored.',
            );
        }

        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, (int) $transfer->to_site_id);
        }

        $existing = ReceivingSession::query()
            ->where('transferring_session_id', $transfer->getKey())
            ->first();

        if ($existing !== null) {
            if ($existing->status === 'cancelled') {
                $existing = $this->reopenCancelledTransferReceiveSession($existing, $transfer);
            }

            $this->propagateScanFirstConfirmsToTransferReceiveSession->handle($existing, $openedBy);

            return $existing->fresh();
        }

        if ($transfer->status !== 'in_transit') {
            throw new InvalidArgumentException(
                "Transfer receive requires transferring session status in_transit; got [{$transfer->status}].",
            );
        }

        $confirmedLines = TransferringScanLine::query()
            ->where('transferring_session_id', $transfer->getKey())
            ->whereIn('status', ['confirmed', 'received'])
            ->orderBy('id')
            ->get(['id', 'epc_id', 'status']);

        $expectedCount = $confirmedLines->count();

        $session = DB::transaction(function () use ($transfer, $openedBy, $confirmedLines, $expectedCount): ReceivingSession {
            $session = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::TransferReceive,
                'epcis_document_id' => null,
                'transferring_session_id' => $transfer->getKey(),
                'matched_epcis_document_id' => null,
                'trading_partner_id' => null,
                'site_id' => $transfer->to_site_id,
                'status' => 'open',
                'expected_parent_count' => $expectedCount,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_by' => $openedBy,
                'opened_at' => now(),
            ]);

            $now = now();
            $rows = [];
            foreach ($confirmedLines as $line) {
                $rows[] = [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $line->epc_id,
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

            return $session->refresh();
        });

        $this->propagateScanFirstConfirmsToTransferReceiveSession->handle($session, $openedBy);

        return $session->fresh();
    }

    private function reopenCancelledTransferReceiveSession(
        ReceivingSession $session,
        TransferringSession $transfer,
    ): ReceivingSession {
        if ($session->receiving_events_generated_at !== null || $session->receiving_epcis_document_id !== null) {
            throw new DomainException('Cannot reopen receiving: session already has authored receiving EPCIS.');
        }

        return DB::transaction(function () use ($session, $transfer): ReceivingSession {
            $session = ReceivingSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->status !== 'cancelled') {
                return $session;
            }

            $this->revertTransferReceiveReceivingMarks->handle($session);

            $confirmedLines = TransferringScanLine::query()
                ->where('transferring_session_id', $transfer->getKey())
                ->whereIn('status', ['confirmed', 'received'])
                ->orderBy('id')
                ->get(['id', 'epc_id', 'status']);

            $expectedCount = $confirmedLines->count();

            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->delete();

            $now = now();
            $rows = [];

            foreach ($confirmedLines as $line) {
                $rows[] = [
                    'receiving_session_id' => $session->getKey(),
                    'epc_id' => $line->epc_id,
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
                'site_id' => $transfer->to_site_id,
                'expected_parent_count' => $expectedCount,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'completed_at' => null,
            ];

            if (Schema::hasColumn('receiving_sessions', 'cancelled_at')) {
                $updates['cancelled_at'] = null;
            }

            $session->forceFill($updates)->save();

            return $session->refresh();
        });
    }
}
