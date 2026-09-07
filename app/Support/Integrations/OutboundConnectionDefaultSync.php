<?php

namespace App\Support\Integrations;

use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;

final class OutboundConnectionDefaultSync
{
    /**
     * At most one default per overlapping partner scope within a conformance band
     * (and among globals in that band).
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

        $band = OutboundConnectionKindValidator::conformanceBand($connection->conformanceState());

        $query = OutboundConnection::query()
            ->whereKeyNot($connection->getKey())
            ->where('is_default', true)
            ->whereIn('conformance_state', $band);

        if ($partnerIds === []) {
            $query->whereDoesntHave('tradingPartners');
        } else {
            $query->whereHas(
                'tradingPartners',
                fn ($partners) => $partners->whereIn('trading_partners.id', $partnerIds),
            );
        }

        // Prefer clearing other hubs for the same provider when this is a B2B hub.
        if (in_array($connection->transport, [
            OutboundTransport::Https,
            OutboundTransport::Sftp,
            OutboundTransport::As2,
        ], true) && $connection->serialization_provider !== null) {
            $query->where(function ($q) use ($connection, $partnerIds): void {
                $q->where('serialization_provider', $connection->serialization_provider->value);
                if ($partnerIds !== []) {
                    $q->orWhereHas(
                        'tradingPartners',
                        fn ($partners) => $partners->whereIn('trading_partners.id', $partnerIds),
                    );
                }
            });
        }

        $query->update(['is_default' => false]);
    }
}
