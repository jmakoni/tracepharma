<?php

namespace App\Support\Dashboard;

use App\Enums\CustomerOnboardingStatus;
use App\Enums\TenantProfile;
use App\Models\CustomerOnboarding;
use App\Models\DemoRequest;
use App\Models\EpcisHubRoute;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Tenant;
use App\Support\AggregationLinkForeignKeyDoctor;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Fda\FdaImportRunStatus;
use App\Support\Fda\FdaRegistryStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AdminDashboardMetrics
{
    public static function make(): self
    {
        return new self;
    }

    /**
     * @return array{
     *     total: int,
     *     by_profile: list<array{profile: string, label: string, count: int}>,
     *     by_status: list<array{status: string, label: string, count: int}>,
     *     rows: list<array{profile: string, profile_label: string, status: string, status_label: string, count: int}>,
     *     as_of: Carbon
     * }
     */
    public function tenantCensus(): array
    {
        $grouped = Tenant::query()
            ->select(['profile', 'status', DB::raw('COUNT(*) as aggregate')])
            ->groupBy('profile', 'status')
            ->get();

        $byProfile = [];
        $byStatus = [];
        $rows = [];
        $total = 0;

        foreach ($grouped as $row) {
            $profile = $this->stringValue($row->getAttribute('profile'));
            $status = $this->stringValue($row->getAttribute('status'));
            $count = (int) $row->getAttribute('aggregate');
            $total += $count;

            $byProfile[$profile] = ($byProfile[$profile] ?? 0) + $count;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + $count;

            $rows[] = [
                'profile' => $profile,
                'profile_label' => $this->profileLabel($profile),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'count' => $count,
            ];
        }

        ksort($byProfile);
        ksort($byStatus);

        return [
            'total' => $total,
            'by_profile' => array_values(array_map(
                fn (string $profile, int $count): array => [
                    'profile' => $profile,
                    'label' => $this->profileLabel($profile),
                    'count' => $count,
                ],
                array_keys($byProfile),
                $byProfile,
            )),
            'by_status' => array_values(array_map(
                fn (string $status, int $count): array => [
                    'status' => $status,
                    'label' => $this->statusLabel($status),
                    'count' => $count,
                ],
                array_keys($byStatus),
                $byStatus,
            )),
            'rows' => $rows,
            'as_of' => now(),
        ];
    }

    /**
     * @return array{
     *     submitted: int,
     *     approved: int,
     *     demo_requests_last_7d: int,
     *     since: Carbon,
     *     as_of: Carbon
     * }
     */
    public function onboardingQueue(): array
    {
        $since = now()->subDays(7);

        $counts = CustomerOnboarding::query()
            ->select(['status', DB::raw('COUNT(*) as aggregate')])
            ->whereIn('status', [
                CustomerOnboardingStatus::Submitted->value,
                CustomerOnboardingStatus::Approved->value,
            ])
            ->groupBy('status')
            ->get();

        $submitted = 0;
        $approved = 0;

        foreach ($counts as $row) {
            $status = $row->status instanceof CustomerOnboardingStatus
                ? $row->status->value
                : $this->stringValue($row->getAttribute('status'));

            if ($status === CustomerOnboardingStatus::Submitted->value) {
                $submitted = (int) $row->getAttribute('aggregate');
            }

            if ($status === CustomerOnboardingStatus::Approved->value) {
                $approved = (int) $row->getAttribute('aggregate');
            }
        }

        return [
            'submitted' => $submitted,
            'approved' => $approved,
            'demo_requests_last_7d' => (int) DemoRequest::query()->where('created_at', '>=', $since)->count(),
            'since' => $since,
            'as_of' => now(),
        ];
    }

    /**
     * @return array{
     *     pending_match_reviews: int,
     *     unresolved_unmatched: int,
     *     as_of: Carbon
     * }
     */
    /**
     * @return array{
     *     organizations: int,
     *     establishments: int,
     *     facilities: int,
     *     licenses: int,
     *     products: int,
     *     as_of: Carbon
     * }
     */
    public function registryCensus(): array
    {
        return [
            'organizations' => (int) FdaOrganization::query()->count(),
            'establishments' => (int) FdaEstablishment::query()->count(),
            'facilities' => (int) FdaWddFacility::query()->count(),
            'licenses' => (int) FdaWddLicense::query()->count(),
            'products' => (int) FdaProduct::query()->count(),
            'as_of' => now(),
        ];
    }

    public function registryExceptions(): array
    {
        return [
            'pending_match_reviews' => (int) FdaOrganizationMatchReview::query()->pending()->count(),
            'unresolved_unmatched' => (int) FdaWdd3plUnmatched::query()->unresolved()->count(),
            'as_of' => now(),
        ];
    }

    /**
     * @return array{
     *     incomplete: int,
     *     failed: int,
     *     partial: int,
     *     sources: list<array{source: string, label: string, outcome: string|null, last_sync: string}>,
     *     as_of: Carbon
     * }
     */
    public function importHealth(): array
    {
        $incomplete = (int) FdaImportRun::query()->whereNull('completed_at')->count();
        $failed = 0;
        $partial = 0;
        $sources = [];

        foreach (FdaImportRunStatus::cards() as $card) {
            $outcome = is_string($card['outcome'] ?? null) ? $card['outcome'] : null;

            if ($outcome === FdaRegistryStatus::IMPORT_FAILED) {
                $failed++;
            }

            if ($outcome === FdaRegistryStatus::IMPORT_PARTIAL) {
                $partial++;
            }

            $sources[] = [
                'source' => (string) $card['source'],
                'label' => (string) $card['label'],
                'outcome' => $outcome,
                'last_sync' => (string) ($card['last_sync'] ?? 'Never'),
            ];
        }

        return [
            'incomplete' => $incomplete,
            'failed' => $failed,
            'partial' => $partial,
            'sources' => $sources,
            'as_of' => now(),
        ];
    }

    /**
     * @return array{
     *     environments: list<array{environment: string, label: string, ok: bool, has_token: bool, provider_count: int, host: string}>,
     *     active_routes: int,
     *     as_of: Carbon
     * }
     */
    public function hubHealth(): array
    {
        $config = app(EpcisHubPlatformConfig::class);
        $environments = [];

        foreach (EpcisHubPlatformConfig::ENVIRONMENTS as $environment) {
            $token = $config->hubToken($environment);
            $providers = $config->enabledProviders($environment);
            $host = $config->host($environment);

            $environments[] = [
                'environment' => $environment,
                'label' => match ($environment) {
                    'demo' => 'Demo',
                    'stage' => 'Stage',
                    'prod' => 'Prod',
                    default => ucfirst($environment),
                },
                'ok' => is_string($token) && $token !== '' && $providers !== [] && $host !== '',
                'has_token' => is_string($token) && $token !== '',
                'provider_count' => count($providers),
                'host' => $host,
            ];
        }

        return [
            'environments' => $environments,
            'active_routes' => (int) EpcisHubRoute::query()->where('is_active', true)->count(),
            'aggregation_link_fk_drift' => $this->aggregationLinkFkDrift(),
            'as_of' => now(),
        ];
    }

    /**
     * @return array{count: int, checked_at: Carbon|null, never_checked: bool}
     */
    public function aggregationLinkFkDrift(): array
    {
        $cached = Cache::get(AggregationLinkForeignKeyDoctor::LAST_AUDIT_CACHE_KEY);

        if (! is_array($cached)) {
            return [
                'count' => 0,
                'checked_at' => null,
                'never_checked' => true,
            ];
        }

        $issues = $cached['issues'] ?? [];
        $checkedAt = isset($cached['checked_at']) && is_string($cached['checked_at'])
            ? Carbon::parse($cached['checked_at'])
            : null;

        return [
            'count' => is_array($issues) ? count($issues) : 0,
            'checked_at' => $checkedAt,
            'never_checked' => $checkedAt === null,
        ];
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof TenantProfile) {
            return $value->value;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : '';
    }

    private function profileLabel(string $profile): string
    {
        $case = TenantProfile::tryFrom($profile);

        return $case?->label() ?? ($profile !== '' ? $profile : 'Unknown');
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'suspended' => 'Suspended',
            '' => 'Unknown',
            default => ucfirst($status),
        };
    }
}
