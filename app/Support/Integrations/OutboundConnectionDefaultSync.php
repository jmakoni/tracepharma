<?php

namespace App\Support\Integrations;

use App\Models\OutboundConnection;

final class OutboundConnectionDefaultSync
{
    /**
     * At most one default per overlapping partner scope (including the global
     * empty-partner group).
     */
    public static function ensureSingleDefault(OutboundConnection $connection): void
    {
        if (! $connection->is_default) {
            return;
        }

        $partnerIds = $connection->tradingPartners()
            ->pluck('trading_partners.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $query = OutboundConnection::query()
            ->whereKeyNot($connection->getKey())
            ->where('is_default', true);

        if ($partnerIds === []) {
            $query->whereDoesntHave('tradingPartners');
        } else {
            $query->whereHas(
                'tradingPartners',
                fn ($partners) => $partners->whereIn('trading_partners.id', $partnerIds),
            );
        }

        $query->update(['is_default' => false]);
    }
}
