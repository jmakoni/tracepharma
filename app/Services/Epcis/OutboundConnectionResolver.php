<?php

namespace App\Services\Epcis;

use App\Enums\OutboundTransport;
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
     *
     * Note: Email connections may be returned when they are the partner/global default
     * (explicit operator choice). Unpinned transmit uses resolveWithLadder() instead,
     * which never auto-selects Email.
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

    /**
     * Ladder for unpinned documents:
     * 1) Partner-scoped active HTTPS/SFTP/AS2 (is_default first)
     * 2) Global active HTTPS/SFTP/AS2
     * 3) Active Client portal (partner-scoped, then global/system)
     * 4) null → skip
     *
     * Email is never auto-selected.
     */
    public function resolveWithLadder(?int $tradingPartnerId): ?OutboundConnection
    {
        $b2b = $this->resolveActiveB2b($tradingPartnerId);
        if ($b2b !== null) {
            return $b2b;
        }

        return $this->resolveActivePortal($tradingPartnerId);
    }

    private function resolveActiveB2b(?int $tradingPartnerId): ?OutboundConnection
    {
        $base = OutboundConnection::query()
            ->where('is_active', true)
            ->whereIn('transport', [
                OutboundTransport::Https,
                OutboundTransport::Sftp,
                OutboundTransport::As2,
            ]);

        if ($tradingPartnerId !== null) {
            $partnerScoped = (clone $base)
                ->where('trading_partner_id', $tradingPartnerId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if ($partnerScoped !== null) {
                return $partnerScoped;
            }

            return (clone $base)
                ->whereNull('trading_partner_id')
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();
        }

        return $base->whereNull('trading_partner_id')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    private function resolveActivePortal(?int $tradingPartnerId): ?OutboundConnection
    {
        $base = OutboundConnection::query()
            ->where('is_active', true)
            ->where('transport', OutboundTransport::Portal);

        if ($tradingPartnerId !== null) {
            $partnerScoped = (clone $base)
                ->where('trading_partner_id', $tradingPartnerId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if ($partnerScoped !== null) {
                return $partnerScoped;
            }
        }

        return OutboundConnection::query()
            ->where('is_active', true)
            ->where('transport', OutboundTransport::Portal)
            ->where(function ($query): void {
                $query->whereNull('trading_partner_id')
                    ->orWhere('system_key', OutboundConnection::SYSTEM_KEY_CLIENT_PORTAL);
            })
            ->orderByDesc('is_system')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
