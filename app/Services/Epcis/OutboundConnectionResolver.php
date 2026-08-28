<?php

namespace App\Services\Epcis;

use App\Models\OutboundConnection;

final class OutboundConnectionResolver
{
    /**
     * A partner-scoped outbound connection must match the document customer. Global
     * connections (no trading partner) may route any shipment.
     */
    public static function connectionMatchesPartner(OutboundConnection $connection, ?int $partnerId): bool
    {
        if ($connection->trading_partner_id === null) {
            return true;
        }

        if ($partnerId === null) {
            return false;
        }

        return (int) $connection->trading_partner_id === $partnerId;
    }

    /**
     * Fail closed: a document only routes through a connection scoped to its own
     * trading partner (or, for partner-less documents, an explicitly unscoped/pinned
     * connection). Never falls back to an arbitrary active connection belonging to a
     * different partner — that would leak one partner's document onto another's
     * endpoint/credentials.
     */
    public function resolve(?int $tradingPartnerId): ?OutboundConnection
    {
        $base = OutboundConnection::query()
            ->where('is_active', true);

        if ($tradingPartnerId !== null) {
            return $base->where('trading_partner_id', $tradingPartnerId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();
        }

        return $base->whereNull('trading_partner_id')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
