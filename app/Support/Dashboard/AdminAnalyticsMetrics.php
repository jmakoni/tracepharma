<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Enums\CustomerOnboardingStatus;
use App\Models\CustomerOnboarding;
use App\Models\DemoRequest;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Fda\FdaImportRunStatus;
use App\Support\Fda\FdaRegistryStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

final class AdminAnalyticsMetrics
{
    public function __construct(
        private readonly int $rangeDays = 30,
    ) {}

    public static function make(int $rangeDays = 30): self
    {
        return new self($rangeDays === 7 ? 7 : 30);
    }

    /**
     * @return array<string, mixed>
     */
    public function forKey(string $key): array
    {
        return match ($key) {
            'tenant_growth' => $this->tenantGrowth($this->rangeDays),
            'registry_growth' => $this->registryGrowth($this->rangeDays),
            'onboarding_funnel' => $this->onboardingFunnel(),
            'demo_volume' => $this->demoVolume($this->rangeDays),
            'import_trends' => $this->importTrends($this->rangeDays),
            'unmatched_aging' => $this->unmatchedAging(),
            'match_review_aging' => $this->matchReviewAging(),
            'hub_coverage' => $this->hubCoverage(),
            'activity_volume' => $this->activityVolume($this->rangeDays),
            default => ['key' => $key],
        };
    }

    /**
     * @return array{
     *     days: list<array{date: string, demo: int, stage: int, prod: int, unset: int, total: int}>,
     *     total: int,
     *     by_environment: list<array{environment: string, label: string, count: int}>
     * }
     */
    public function tenantGrowth(int $days): array
    {
        $since = $this->sinceFor($days);
        $dayMap = $this->emptyDayMap($since);
        $envKeys = ['demo', 'stage', 'prod', 'unset'];
        $byEnv = array_fill_keys($envKeys, 0);
        $rows = [];

        foreach ($dayMap as $date => $_) {
            $rows[$date] = array_merge(['date' => $date], array_fill_keys($envKeys, 0), ['total' => 0]);
        }

        $aggregates = $this->tenants()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, inbound_environment, COUNT(*) as aggregate')
            ->groupByRaw('DATE(created_at), inbound_environment')
            ->get();

        foreach ($aggregates as $row) {
            $day = (string) $row->day;
            if (! isset($rows[$day])) {
                continue;
            }

            $environment = $this->normalizeEnvironment($row->inbound_environment);
            if (! array_key_exists($environment, $byEnv)) {
                $environment = 'unset';
            }

            $count = (int) $row->aggregate;
            $rows[$day][$environment] += $count;
            $rows[$day]['total'] += $count;
            $byEnv[$environment] += $count;
        }

        return [
            'days' => array_values($rows),
            'total' => array_sum($byEnv),
            'by_environment' => array_map(
                fn (string $environment): array => [
                    'environment' => $environment,
                    'label' => $this->environmentLabel($environment),
                    'count' => $byEnv[$environment],
                ],
                $envKeys,
            ),
        ];
    }

    /**
     * @return array{
     *     days: list<array{date: string, organizations: int, products: int, total: int}>,
     *     organizations_total: int,
     *     products_total: int,
     *     total: int
     * }
     */
    public function registryGrowth(int $days): array
    {
        $since = $this->sinceFor($days);
        $dayMap = $this->emptyDayMap($since);
        $rows = [];

        foreach ($dayMap as $date => $_) {
            $rows[$date] = [
                'date' => $date,
                'organizations' => 0,
                'products' => 0,
                'total' => 0,
            ];
        }

        $organizationsTotal = 0;
        $productsTotal = 0;

        $orgAggregates = FdaOrganization::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->groupByRaw('DATE(created_at)')
            ->get();

        foreach ($orgAggregates as $row) {
            $day = (string) $row->day;
            if (! isset($rows[$day])) {
                continue;
            }

            $count = (int) $row->aggregate;
            $rows[$day]['organizations'] += $count;
            $rows[$day]['total'] += $count;
            $organizationsTotal += $count;
        }

        $productAggregates = FdaProduct::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->groupByRaw('DATE(created_at)')
            ->get();

        foreach ($productAggregates as $row) {
            $day = (string) $row->day;
            if (! isset($rows[$day])) {
                continue;
            }

            $count = (int) $row->aggregate;
            $rows[$day]['products'] += $count;
            $rows[$day]['total'] += $count;
            $productsTotal += $count;
        }

        return [
            'days' => array_values($rows),
            'organizations_total' => $organizationsTotal,
            'products_total' => $productsTotal,
            'total' => $organizationsTotal + $productsTotal,
        ];
    }

    /**
     * @return array{
     *     statuses: list<array{key: string, label: string, count: int}>,
     *     total: int,
     *     provisioned: int,
     *     average_days_to_provisioned: float|null
     * }
     */
    public function onboardingFunnel(): array
    {
        $counts = CustomerOnboarding::on($this->central())
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $statuses = [];
        $total = 0;

        foreach (CustomerOnboardingStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);
            $total += $count;
            $statuses[] = [
                'key' => $status->value,
                'label' => $status->label(),
                'count' => $count,
            ];
        }

        $average = CustomerOnboarding::on($this->central())
            ->where('status', CustomerOnboardingStatus::Provisioned->value)
            ->whereNotNull('provisioned_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(DAY, created_at, provisioned_at)) as avg_days')
            ->value('avg_days');

        return [
            'statuses' => $statuses,
            'total' => $total,
            'provisioned' => (int) ($counts[CustomerOnboardingStatus::Provisioned->value] ?? 0),
            'average_days_to_provisioned' => $average !== null ? round((float) $average, 1) : null,
        ];
    }

    /**
     * @return array{days: list<array{date: string, count: int}>, total: int}
     */
    public function demoVolume(int $days): array
    {
        return $this->countsByDay(
            DemoRequest::on($this->central())->where('created_at', '>=', $this->sinceFor($days)),
            'created_at',
            $this->sinceFor($days),
        );
    }

    /**
     * @return array{
     *     days: list<array{date: string, success: int, partial: int, failed: int}>,
     *     sources: list<array{source: string, label: string, success: int, partial: int, failed: int, total: int}>,
     *     success: int,
     *     partial: int,
     *     failed: int
     * }
     */
    public function importTrends(int $days): array
    {
        $since = $this->sinceFor($days);
        $dayMap = $this->emptyDayMap($since);
        $daysOut = [];

        foreach ($dayMap as $date => $_) {
            $daysOut[$date] = [
                'date' => $date,
                'success' => 0,
                'partial' => 0,
                'failed' => 0,
            ];
        }

        $sourceTotals = [];
        foreach (array_keys(FdaImportRunStatus::LABELS) as $source) {
            $sourceTotals[$source] = [
                'source' => $source,
                'label' => FdaImportRunStatus::LABELS[$source],
                'success' => 0,
                'partial' => 0,
                'failed' => 0,
                'total' => 0,
            ];
        }

        $aggregates = FdaImportRun::query()
            ->where(function ($query) use ($since): void {
                $query->where('started_at', '>=', $since)
                    ->orWhere('completed_at', '>=', $since);
            })
            ->selectRaw('DATE(COALESCE(completed_at, started_at)) as day')
            ->selectRaw('source')
            ->selectRaw(
                "CASE
                    WHEN completed_at IS NULL THEN ?
                    WHEN (COALESCE(rows_skipped, 0) + COALESCE(rows_sent_to_review, 0)) > 0 THEN ?
                    ELSE ?
                END as outcome",
                [
                    FdaRegistryStatus::IMPORT_FAILED,
                    FdaRegistryStatus::IMPORT_PARTIAL,
                    FdaRegistryStatus::IMPORT_SUCCESS,
                ],
            )
            ->selectRaw('COUNT(*) as aggregate')
            ->groupByRaw('DATE(COALESCE(completed_at, started_at)), source, outcome')
            ->get();

        $success = 0;
        $partial = 0;
        $failed = 0;

        foreach ($aggregates as $row) {
            $day = (string) $row->day;
            $outcome = (string) $row->outcome;
            $count = (int) $row->aggregate;
            $source = (string) $row->source;

            if (isset($daysOut[$day]) && in_array($outcome, ['success', 'partial', 'failed'], true)) {
                $daysOut[$day][$outcome] += $count;
            }

            if (! isset($sourceTotals[$source])) {
                $sourceTotals[$source] = [
                    'source' => $source,
                    'label' => $source !== '' ? $source : 'Unknown',
                    'success' => 0,
                    'partial' => 0,
                    'failed' => 0,
                    'total' => 0,
                ];
            }

            if (in_array($outcome, ['success', 'partial', 'failed'], true)) {
                $sourceTotals[$source][$outcome] += $count;
                $sourceTotals[$source]['total'] += $count;
            }

            match ($outcome) {
                FdaRegistryStatus::IMPORT_SUCCESS => $success += $count,
                FdaRegistryStatus::IMPORT_PARTIAL => $partial += $count,
                default => $failed += $count,
            };
        }

        return [
            'days' => array_values($daysOut),
            'sources' => array_values($sourceTotals),
            'success' => $success,
            'partial' => $partial,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{bands: list<array{key: string, label: string, count: int}>, total: int}
     */
    public function unmatchedAging(): array
    {
        return $this->ageBands(
            FdaWdd3plUnmatched::query()->unresolved(),
            'last_seen_at',
        );
    }

    /**
     * @return array{
     *     bands: list<array{key: string, label: string, count: int}>,
     *     confidences: list<array{key: string, label: string, count: int}>,
     *     total: int
     * }
     */
    public function matchReviewAging(): array
    {
        $aging = $this->ageBands(
            FdaOrganizationMatchReview::query()->pending(),
            'created_at',
        );

        $confidenceRows = FdaOrganizationMatchReview::query()
            ->pending()
            ->selectRaw(
                "CASE
                    WHEN confidence IS NULL THEN 'unset'
                    WHEN confidence >= 0.8 THEN 'high'
                    WHEN confidence >= 0.5 THEN 'medium'
                    ELSE 'low'
                END as confidence_band",
            )
            ->selectRaw('COUNT(*) as aggregate')
            ->groupByRaw('confidence_band')
            ->pluck('aggregate', 'confidence_band')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $confidenceOrder = [
            'high' => 'High (≥ 0.80)',
            'medium' => 'Medium (0.50–0.79)',
            'low' => 'Low (< 0.50)',
            'unset' => 'Unset',
        ];

        return [
            'bands' => $aging['bands'],
            'confidences' => array_map(
                fn (string $key, string $label): array => [
                    'key' => $key,
                    'label' => $label,
                    'count' => $confidenceRows[$key] ?? 0,
                ],
                array_keys($confidenceOrder),
                $confidenceOrder,
            ),
            'total' => $aging['total'],
        ];
    }

    /**
     * @return array{
     *     environments: list<array{environment: string, label: string, tenants_with_providers: int, tenants_with_active_routes: int, active_routes: int}>,
     *     tenants_with_providers: int,
     *     active_routes: int
     * }
     */
    public function hubCoverage(): array
    {
        $envKeys = EpcisHubPlatformConfig::ENVIRONMENTS;
        $providerRows = $this->tenants()
            ->whereNotNull('hub_providers')
            ->whereRaw("hub_providers NOT IN ('[]', 'null', '')")
            ->selectRaw('inbound_environment, COUNT(*) as aggregate')
            ->groupBy('inbound_environment')
            ->get();

        $providerCounts = [];
        foreach ($providerRows as $row) {
            $environment = $this->normalizeEnvironment($row->inbound_environment);
            $providerCounts[$environment] = ($providerCounts[$environment] ?? 0) + (int) $row->aggregate;
        }

        $routeRows = DB::connection($this->central())->table('epcis_hub_routes')
            ->where('epcis_hub_routes.is_active', true)
            ->join('tenants', 'tenants.id', '=', 'epcis_hub_routes.tenant_id')
            ->selectRaw('tenants.inbound_environment, COUNT(*) as routes, COUNT(DISTINCT epcis_hub_routes.tenant_id) as tenants')
            ->groupBy('tenants.inbound_environment')
            ->get();

        $routeCounts = [];
        $tenantRouteCounts = [];
        foreach ($routeRows as $row) {
            $environment = $this->normalizeEnvironment($row->inbound_environment);
            $routeCounts[$environment] = ($routeCounts[$environment] ?? 0) + (int) $row->routes;
            $tenantRouteCounts[$environment] = ($tenantRouteCounts[$environment] ?? 0) + (int) $row->tenants;
        }

        $environments = [];
        $tenantsWithProviders = 0;
        $activeRoutes = 0;

        foreach ([...$envKeys, 'unset'] as $environment) {
            $providers = $providerCounts[$environment] ?? 0;
            $routes = $routeCounts[$environment] ?? 0;
            $tenantsWithRoutes = $tenantRouteCounts[$environment] ?? 0;

            if ($environment === 'unset' && $providers === 0 && $routes === 0) {
                continue;
            }

            $tenantsWithProviders += $providers;
            $activeRoutes += $routes;
            $environments[] = [
                'environment' => $environment,
                'label' => $this->environmentLabel($environment),
                'tenants_with_providers' => $providers,
                'tenants_with_active_routes' => $tenantsWithRoutes,
                'active_routes' => $routes,
            ];
        }

        return [
            'environments' => $environments,
            'tenants_with_providers' => $tenantsWithProviders,
            'active_routes' => $activeRoutes,
        ];
    }

    /**
     * @return array{days: list<array{date: string, count: int}>, total: int}
     */
    public function activityVolume(int $days): array
    {
        return $this->countsByDay(
            Activity::on($this->central())->where('created_at', '>=', $this->sinceFor($days)),
            'created_at',
            $this->sinceFor($days),
        );
    }

    public function since(): Carbon
    {
        return $this->sinceFor($this->rangeDays);
    }

    private function sinceFor(int $days): Carbon
    {
        return now()->subDays($days === 7 ? 7 : 30)->startOfDay();
    }

    /**
     * @return array<string, int>
     */
    private function emptyDayMap(Carbon $since): array
    {
        $days = [];
        $cursor = $since->copy();
        $end = now()->startOfDay();

        while ($cursor->lte($end)) {
            $days[$cursor->toDateString()] = 0;
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array{days: list<array{date: string, count: int}>, total: int}
     */
    private function countsByDay($query, string $column, Carbon $since): array
    {
        $dayMap = $this->emptyDayMap($since);
        $counts = $query
            ->selectRaw('DATE('.$column.') as day, COUNT(*) as aggregate')
            ->groupByRaw('DATE('.$column.')')
            ->pluck('aggregate', 'day')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $rows = [];
        $total = 0;

        foreach ($dayMap as $date => $_) {
            $count = $counts[$date] ?? 0;
            $total += $count;
            $rows[] = [
                'date' => $date,
                'count' => $count,
            ];
        }

        return [
            'days' => $rows,
            'total' => $total,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return array{bands: list<array{key: string, label: string, count: int}>, total: int}
     */
    private function ageBands($query, string $column): array
    {
        $oneDay = now()->subDay();
        $threeDays = now()->subDays(3);
        $sevenDays = now()->subDays(7);

        $rows = (clone $query)
            ->toBase()
            ->selectRaw(
                "CASE
                    WHEN {$column} IS NULL THEN 'unknown'
                    WHEN {$column} >= ? THEN '0-1d'
                    WHEN {$column} >= ? THEN '1-3d'
                    WHEN {$column} >= ? THEN '3-7d'
                    ELSE '7d+'
                END as age_band",
                [$oneDay, $threeDays, $sevenDays],
            )
            ->selectRaw('COUNT(*) as aggregate')
            ->groupByRaw('age_band')
            ->pluck('aggregate', 'age_band')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $bandLabels = [
            '0-1d' => '0–1 day',
            '1-3d' => '1–3 days',
            '3-7d' => '3–7 days',
            '7d+' => '7+ days',
            'unknown' => 'Unknown',
        ];

        $bands = [];
        $total = 0;

        foreach ($bandLabels as $key => $label) {
            if ($key === 'unknown' && ($rows[$key] ?? 0) === 0) {
                continue;
            }

            $count = $rows[$key] ?? 0;
            $total += $count;
            $bands[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
            ];
        }

        return [
            'bands' => $bands,
            'total' => $total,
        ];
    }

    private function normalizeEnvironment(mixed $environment): string
    {
        if (! is_string($environment) || $environment === '') {
            return 'unset';
        }

        return $environment;
    }

    private function environmentLabel(string $environment): string
    {
        return match ($environment) {
            'demo' => 'Demo',
            'stage' => 'Stage',
            'prod' => 'Prod',
            'unset' => 'Unset',
            default => ucfirst($environment),
        };
    }

    private function central(): string
    {
        return (string) config('tenancy.database.central_connection', config('database.default'));
    }

    private function tenants(): \Illuminate\Database\Query\Builder
    {
        return DB::connection($this->central())->table('tenants');
    }
}
