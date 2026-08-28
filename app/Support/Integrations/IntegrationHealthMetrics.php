<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Enums\OutboundTransport;
use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Logging\RedactsUrls;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IntegrationHealthMetrics
{
    /**
     * @return array<string, int>
     */
    public function inboundStatusCountsLast24h(?User $user = null): array
    {
        $since = now()->subDay();

        $query = EpcisDocument::query()
            ->inboundCatalog()
            ->where('received_at', '>=', $since);

        $this->applyInboundSiteScope($query, $user);

        return $query
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function outboundTransmissionCountsLast24h(?User $user = null): array
    {
        $since = now()->subDay();

        $query = EpcisDocument::query()
            ->where('direction', 'outbound')
            ->where(function (Builder $q) use ($since): void {
                $q->where('sent_at', '>=', $since)
                    ->orWhere(function (Builder $q2) use ($since): void {
                        $q2->whereNull('sent_at')
                            ->where('creation_date', '>=', $since);
                    });
            });

        $this->applyOutboundSiteScope($query, $user);

        return $query
            ->select(
                DB::raw("COALESCE(transmission_status, 'pending') as transmission_bucket"),
                DB::raw('count(*) as aggregate'),
            )
            ->groupBy('transmission_bucket')
            ->pluck('aggregate', 'transmission_bucket')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return Collection<int, InboundConnection>
     */
    public function inboundConnections(): Collection
    {
        return InboundConnection::query()
            ->with('tradingPartner:id,name')
            ->select([
                'id',
                'name',
                'transport',
                'is_active',
                'last_received_at',
                'last_polled_at',
                'last_error',
                'trading_partner_id',
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, OutboundConnection>
     */
    public function outboundConnections(): Collection
    {
        return OutboundConnection::query()
            ->with('tradingPartner:id,name')
            ->select([
                'id',
                'name',
                'transport',
                'is_active',
                'last_sent_at',
                'last_error',
                'trading_partner_id',
                'settings',
            ])
            ->orderBy('name')
            ->get();
    }

    public function activeLegacySftpOutboundCount(): int
    {
        return OutboundConnection::query()
            ->where('transport', OutboundTransport::Sftp)
            ->where('is_active', true)
            ->count();
    }

    public function hasActiveLegacySftpOutbound(): bool
    {
        return $this->activeLegacySftpOutboundCount() > 0;
    }

    public function redactLastError(?string $error): ?string
    {
        return RedactsUrls::redactUrlsInText($error);
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     */
    private function applyInboundSiteScope(Builder $query, ?User $user): void
    {
        $user ??= auth()->user();

        if ($user === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return;
        }

        $query->whereIn('ship_to_site_id', SiteAccess::userSiteIds($user));
    }

    /**
     * @param  Builder<EpcisDocument>  $query
     */
    private function applyOutboundSiteScope(Builder $query, ?User $user): void
    {
        $user ??= auth()->user();

        if ($user === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return;
        }

        $query->whereIn('ship_from_site_id', SiteAccess::userSiteIds($user));
    }
}
