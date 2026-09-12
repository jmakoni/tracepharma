<?php

declare(strict_types=1);

namespace App\Support\BuyingGroup;

use App\Enums\SiteAtpReadinessStatus;
use App\Models\Exceptions\ExceptionCase;
use App\Models\InboundConnection;
use App\Models\Site;
use App\Support\Integrations\ConnectionHealthTracker;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\MasterData\SiteAtpReadiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only aggregates from a member pharmacy tenant (projected / chunked).
 * Call only while that member's tenancy is initialized.
 */
final class BuyingGroupMemberMetricsCollector
{
    private const PARTNER_FACT_LIMIT = 200;

    private const ATP_GAP_STATUSES = [
        SiteAtpReadinessStatus::Expired,
        SiteAtpReadinessStatus::Expiring,
        SiteAtpReadinessStatus::NoLicenses,
        SiteAtpReadinessStatus::UnknownExpiry,
        SiteAtpReadinessStatus::NeedsReceivingState,
    ];

    /**
     * @return array{
     *     atp_gap_count: int,
     *     exceptions_open: int,
     *     exceptions_aging_7d: int,
     *     connection_unhealthy: bool,
     *     last_epcis_success_at: ?string,
     *     health_score: ?float,
     *     risk_score: ?float,
     *     partners: list<array{
     *         partner_key: string,
     *         partner_name: string,
     *         partner_gln: ?string,
     *         license_status: string,
     *         expires_at: ?string
     *     }>
     * }
     */
    public function collect(): array
    {
        $atpGapCount = 0;
        $partners = [];

        Site::query()
            ->where('is_active', true)
            ->whereHas('tradingPartner', fn (Builder $q): Builder => $q->where('is_active', true))
            ->with([
                'tradingPartner:id,name',
                'atpLicenses' => fn ($q) => $q->where('is_active', true)
                    ->orderByDesc('license_expiration_date')
                    ->limit(5),
            ])
            ->orderBy('id')
            ->chunkById(50, function ($sites) use (&$atpGapCount, &$partners): bool {
                foreach ($sites as $site) {
                    if (count($partners) >= self::PARTNER_FACT_LIMIT) {
                        return false;
                    }

                    /** @var Site $site */
                    if (! AtpLicenseRelevance::siteInComplianceAlertScope($site)) {
                        continue;
                    }

                    $summary = SiteAtpReadiness::summarize($site);
                    $status = $summary['status'];
                    $statusValue = $status instanceof SiteAtpReadinessStatus
                        ? $status->value
                        : (string) $status;

                    if (in_array($status, self::ATP_GAP_STATUSES, true)) {
                        $atpGapCount++;
                    }

                    $expiresAt = $site->atpLicenses
                        ->map(fn ($license) => $license->license_expiration_date)
                        ->filter()
                        ->sortDesc()
                        ->first();

                    $partners[] = [
                        'partner_key' => 'site:'.$site->getKey(),
                        'partner_name' => (string) ($site->tradingPartner?->name ?? $site->name ?? 'Partner'),
                        'partner_gln' => filled($site->gln) ? (string) $site->gln : null,
                        'license_status' => $statusValue,
                        'expires_at' => $expiresAt instanceof Carbon
                            ? $expiresAt->toDateString()
                            : null,
                    ];
                }

                return true;
            });

        $openQuery = ExceptionCase::query()->open();
        $exceptionsOpen = (clone $openQuery)->count();
        $exceptionsAging7d = (clone $openQuery)
            ->where('created_at', '<', now()->subDays(7))
            ->count();

        $connectionUnhealthy = InboundConnection::query()
            ->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->where('consecutive_failures', '>=', ConnectionHealthTracker::ALERT_THRESHOLD)
                    ->orWhere(function (Builder $inner): void {
                        $inner->whereNotNull('last_error')->where('last_error', '!=', '');
                    });
            })
            ->exists();

        $lastSuccess = InboundConnection::query()
            ->whereNotNull('last_success_at')
            ->max('last_success_at');

        if ($lastSuccess === null) {
            $lastSuccess = InboundConnection::query()
                ->whereNotNull('last_received_at')
                ->max('last_received_at');
        }

        $healthScore = $this->computeHealthScore(
            $atpGapCount,
            $exceptionsOpen,
            $exceptionsAging7d,
            $connectionUnhealthy,
        );

        return [
            'atp_gap_count' => $atpGapCount,
            'exceptions_open' => $exceptionsOpen,
            'exceptions_aging_7d' => $exceptionsAging7d,
            'connection_unhealthy' => $connectionUnhealthy,
            'last_epcis_success_at' => is_string($lastSuccess) && $lastSuccess !== ''
                ? $lastSuccess
                : null,
            'health_score' => $healthScore,
            'risk_score' => $healthScore !== null ? round(100 - $healthScore, 2) : null,
            'partners' => $partners,
        ];
    }

    private function computeHealthScore(
        int $atpGaps,
        int $exceptionsOpen,
        int $aging7d,
        bool $connectionUnhealthy,
    ): float {
        $score = 100.0;
        $score -= min(40, $atpGaps * 8);
        $score -= min(30, $exceptionsOpen * 2);
        $score -= min(20, $aging7d * 4);
        if ($connectionUnhealthy) {
            $score -= 15;
        }

        return round(max(0, $score), 2);
    }
}
