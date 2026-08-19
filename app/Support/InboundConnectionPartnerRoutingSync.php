<?php

namespace App\Support;

use App\Models\InboundConnection;
use App\Models\TradingPartner;
use App\Rules\ValidGln;

class InboundConnectionPartnerRoutingSync
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public static function syncFromForm(InboundConnection $connection, array $rows, bool $enabled = true): void
    {
        if (! $enabled) {
            $connection->tradingPartners()->sync([]);

            return;
        }

        $sync = [];

        foreach (array_values($rows) as $row) {
            $partnerId = $row['trading_partner_id'] ?? null;

            if (! $partnerId) {
                continue;
            }

            $senderGln = self::normalizeSenderGln($row['sender_gln'] ?? null);

            $sync[(int) $partnerId] = [
                'sender_gln' => $senderGln,
                'is_default' => filter_var($row['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'priority' => (int) ($row['priority'] ?? 0),
            ];
        }

        $connection->tradingPartners()->sync($sync);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toFormRows(InboundConnection $connection): array
    {
        return $connection->tradingPartners()
            ->orderByPivot('priority')
            ->orderByPivot('id')
            ->get()
            ->map(fn (TradingPartner $partner): array => [
                'trading_partner_id' => $partner->id,
                'sender_gln' => $partner->pivot->sender_gln,
                'is_default' => (bool) $partner->pivot->is_default,
                'priority' => (int) $partner->pivot->priority,
            ])
            ->values()
            ->all();
    }

    /**
     * The 13-digit GLN this mapping routes on, or null.
     *
     * Inbound routing matches this against the GLN read out of the sender's SBDH, so
     * anything that is not a GLN routes nothing — storing it only leaves a mapping that
     * looks configured and silently sends every file to the default partner instead.
     */
    public static function normalizeSenderGln(mixed $gln): ?string
    {
        return ValidGln::normalize($gln);
    }
}
