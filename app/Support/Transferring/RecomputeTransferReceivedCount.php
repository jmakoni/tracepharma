<?php

namespace App\Support\Transferring;

use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;

/**
 * Derive transferring_sessions.received_count from received scan lines — never trust
 * incremental +1/-1 alone, which drifts when lines are compensated or reconciled.
 */
final class RecomputeTransferReceivedCount
{
    public static function forSession(TransferringSession $transfer): int
    {
        return TransferringScanLine::query()
            ->where('transferring_session_id', $transfer->getKey())
            ->where('status', 'received')
            ->count();
    }
}
