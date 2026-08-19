<?php

namespace App\Support\Transferring;

/**
 * Human-facing label for a transferring session's `status` column.
 */
final class TransferringSessionStatus
{
    public static function label(?string $status): string
    {
        return match ($status) {
            'open' => 'Open',
            'in_transit' => 'In transit',
            'completed' => 'Completed',
            null => 'Unknown',
            default => ucfirst($status),
        };
    }
}
