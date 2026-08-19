<?php

namespace App\Actions\Receiving;

use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use DomainException;

/**
 * Clear transferring-side receive marks for a transfer_receive session.
 *
 * Mirrors the transferring updates in ResetReceivingSessionScans::resetTransferReceive.
 * Reopens the linked transfer to in_transit when it was completed solely by partial
 * receive (no receive EPCIS authored).
 */
final class RevertTransferReceiveReceivingMarks
{
    public function handle(ReceivingSession $session): void
    {
        if (! $session->isTransferReceive() || $session->transferring_session_id === null) {
            return;
        }

        $transfer = TransferringSession::query()
            ->whereKey($session->transferring_session_id)
            ->lockForUpdate()
            ->first();

        if ($transfer === null) {
            return;
        }

        if ($transfer->receive_events_generated_at !== null) {
            throw new DomainException(
                'Cannot revert transfer receive marks: transfer receive EPCIS events were already generated.',
            );
        }

        TransferringScanLine::query()
            ->where('transferring_session_id', $transfer->getKey())
            ->where('status', 'received')
            ->update([
                'status' => 'confirmed',
                'received_at' => null,
                'received_by' => null,
                'updated_at' => now(),
            ]);

        $transferUpdates = [
            'received_count' => 0,
            'received_at' => null,
        ];

        if ($transfer->status === 'completed') {
            $transferUpdates['status'] = 'in_transit';
            $transferUpdates['completed_at'] = null;
        }

        $transfer->forceFill($transferUpdates)->save();
    }
}
