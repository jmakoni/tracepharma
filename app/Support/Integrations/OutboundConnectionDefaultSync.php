<?php

namespace App\Support\Integrations;

use App\Models\OutboundConnection;

final class OutboundConnectionDefaultSync
{
    /**
     * At most one default per partner scope (including the global null-partner group).
     */
    public static function ensureSingleDefault(OutboundConnection $connection): void
    {
        if (! $connection->is_default) {
            return;
        }

        OutboundConnection::query()
            ->whereKeyNot($connection->getKey())
            ->where('trading_partner_id', $connection->trading_partner_id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
