<?php

namespace App\Services\Epcis;

use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;
use Illuminate\Database\Eloquent\Builder;

final class OutboundConnectionResolver
{
    /**
     * A partner-scoped outbound connection must match the document customer. Global
     * connections (no linked trading partners) may route any shipment.
     */
    public static function connectionMatchesPartner(OutboundConnection $connection, ?int $partnerId): bool
    {
        if ($connection->isGlobalPartnerScope()) {
            return true;
        }

        if ($partnerId === null) {
            return false;
        }

        if ($connection->relationLoaded('tradingPartners')) {
            return $connection->tradingPartners->contains(
                fn ($partner): bool => (int) $partner->getKey() === $partnerId,
            );
        }

        return $connection->tradingPartners()
            ->where('trading_partners.id', $partnerId)
            ->exists();
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
            return $this->scopedToPartner($base, $tradingPartnerId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();
        }

        return $this->globalScope($base)
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
            $partnerScoped = $this->scopedToPartner((clone $base), $tradingPartnerId)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();

            if ($partnerScoped !== null) {
                return $partnerScoped;
            }

            return null;
        }

        return $this->globalScope($base)
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
            $partnerScoped = $this->scopedToPartner((clone $base), $tradingPartnerId)
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
                $query->whereDoesntHave('tradingPartners')
                    ->orWhere('system_key', OutboundConnection::SYSTEM_KEY_CLIENT_PORTAL);
            })
            ->orderByDesc('is_system')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  Builder<OutboundConnection>  $query
     * @return Builder<OutboundConnection>
     */
    private function scopedToPartner(Builder $query, int $tradingPartnerId): Builder
    {
        return $query->whereHas(
            'tradingPartners',
            fn (Builder $partners): Builder => $partners->where('trading_partners.id', $tradingPartnerId),
        );
    }

    /**
     * @param  Builder<OutboundConnection>  $query
     * @return Builder<OutboundConnection>
     */
    private function globalScope(Builder $query): Builder
    {
        return $query->whereDoesntHave('tradingPartners');
    }
}
