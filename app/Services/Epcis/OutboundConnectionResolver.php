<?php

namespace App\Services\Epcis;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\OutboundConformanceState;
use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;
use App\Support\Integrations\OutboundConnectionKindValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class OutboundConnectionResolver
{
    private bool $lastPartnerB2bAmbiguous = false;

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
        $base = $this->approvedBase();

        if ($tradingPartnerId !== null) {
            return $this->pickPartnerScoped($base, $tradingPartnerId);
        }

        return $this->globalScope($base)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * Ladder for unpinned documents:
     * 1) Partner-scoped active HTTPS/SFTP/AS2 (is_default first)
     * 2) Global active HTTPS/SFTP/AS2 (only when partner is null)
     * 3) Active Client portal (partner-scoped, then global/system)
     * 4) null → skip
     *
     * Email is never auto-selected.
     * Two active hubs for the same partner in one conformance band → null (ambiguous).
     */
    public function resolveWithLadder(?int $tradingPartnerId): ?OutboundConnection
    {
        $this->lastPartnerB2bAmbiguous = false;

        $b2b = $this->resolveActiveB2b($tradingPartnerId);
        if ($b2b !== null) {
            $this->logResolve('partner_b2b', $tradingPartnerId, $b2b);

            return $b2b;
        }

        // Dual hubs for the same partner must not silently fall through to portal.
        if ($this->lastPartnerB2bAmbiguous) {
            return null;
        }

        $portal = $this->resolveActivePortal($tradingPartnerId);
        if ($portal !== null) {
            $this->logResolve('portal', $tradingPartnerId, $portal);

            return $portal;
        }

        $this->logResolve('no_route', $tradingPartnerId, null);

        return null;
    }

    private function logResolve(string $reason, ?int $tradingPartnerId, ?OutboundConnection $connection): void
    {
        Log::info('outbound.resolve.'.$reason, [
            'trading_partner_id' => $tradingPartnerId,
            'connection_id' => $connection?->getKey(),
            'transport' => $connection?->transport?->value,
            'provider' => $connection?->serialization_provider?->value,
        ]);
    }

    private function resolveActiveB2b(?int $tradingPartnerId): ?OutboundConnection
    {
        $base = $this->approvedBase()
            ->whereIn('transport', [
                OutboundTransport::Https,
                OutboundTransport::Sftp,
                OutboundTransport::As2,
            ]);

        if ($tradingPartnerId !== null) {
            return $this->pickPartnerScoped($base, $tradingPartnerId);
        }

        return $this->globalScope($base)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    private function resolveActivePortal(?int $tradingPartnerId): ?OutboundConnection
    {
        $base = $this->approvedBase()
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

        return $this->approvedBase()
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
     * @param  Builder<OutboundConnection>  $base
     */
    private function pickPartnerScoped(Builder $base, int $tradingPartnerId): ?OutboundConnection
    {
        /** @var Collection<int, OutboundConnection> $matches */
        $matches = $this->scopedToPartner((clone $base), $tradingPartnerId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            return null;
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        // Prefer a unique is_default within a single conformance band.
        $byBand = $matches->groupBy(
            fn (OutboundConnection $c): string => implode(',', OutboundConnectionKindValidator::conformanceBand($c->conformanceState())),
        );

        foreach ($byBand as $bandMatches) {
            if ($bandMatches->count() <= 1) {
                continue;
            }

            $defaults = $bandMatches->where('is_default', true);
            if ($defaults->count() === 1) {
                return $defaults->first();
            }

            $this->lastPartnerB2bAmbiguous = true;
            Log::warning('outbound.resolve.ambiguous_outbound_hub', [
                'trading_partner_id' => $tradingPartnerId,
                'connection_ids' => $bandMatches->pluck('id')->all(),
            ]);

            return null;
        }

        // Prefer Live-band membership, else lowest id.
        $liveBand = $matches->filter(
            fn (OutboundConnection $c): bool => in_array(
                $c->conformanceState()->value,
                OutboundConnectionKindValidator::conformanceBand(OutboundConformanceState::Live),
                true,
            ),
        );
        if ($liveBand->isNotEmpty()) {
            $liveDefault = $liveBand->firstWhere('is_default', true);

            return $liveDefault ?? $liveBand->sortBy('id')->first();
        }

        $default = $matches->firstWhere('is_default', true);

        return $default ?? $matches->sortBy('id')->first();
    }

    /**
     * Active + platform-approved connections only; pending/rejected never route traffic.
     *
     * @return Builder<OutboundConnection>
     */
    private function approvedBase(): Builder
    {
        return OutboundConnection::query()
            ->where('is_active', true)
            ->where('approval_status', ConnectionApprovalStatus::Approved->value);
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
