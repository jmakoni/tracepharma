<?php

namespace App\Support\Shipping;

/**
 * Human-facing label for an outbound shipping session's `status` column.
 */
final class OutboundShippingSessionStatus
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
