<?php

namespace App\Actions\Receiving;

use App\Models\Epcis\Epc;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Support\Transferring\RecomputeTransferReceivedCount;
use Illuminate\Support\Facades\DB;

/**
 * Undo a transferring receive mark when the receiving leg could not commit.
 * Mirrors ResetReceivingSessionScans::resetTransferReceive for a single EPC.
 */
final class CompensateTransferReceiveLine
{
    public function handle(TransferringSession $transfer, Epc $epc): void
    {
        DB::transaction(function () use ($transfer, $epc): void {
            $transfer = TransferringSession::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            $transferLine = TransferringScanLine::query()
                ->where('transferring_session_id', $transfer->getKey())
                ->where('epc_id', $epc->getKey())
                ->lockForUpdate()
                ->first();

            if ($transferLine === null || $transferLine->status !== 'received') {
                return;
            }

            $transferLine->forceFill([
                'status' => 'confirmed',
                'received_at' => null,
                'received_by' => null,
            ])->save();

            $receivedCount = RecomputeTransferReceivedCount::forSession($transfer);
            $wasCompleted = $transfer->status === 'completed';

            $transfer->forceFill([
                'received_count' => $receivedCount,
                ...($wasCompleted ? [
                    'status' => 'in_transit',
                    'received_at' => null,
                    'completed_at' => null,
                ] : [
                    'received_at' => null,
                ]),
            ])->save();
        });
    }
}
