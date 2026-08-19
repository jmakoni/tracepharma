<?php

namespace App\Support\Receiving;

/**
 * Human-facing label for a receiving session's `status` column.
 */
final class ReceivingSessionStatus
{
    public static function label(?string $status): string
    {
        return match ($status) {
            'open' => 'Open',
            'in_progress' => 'In progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            null => 'Unknown',
            default => ucfirst($status),
        };
    }
}
