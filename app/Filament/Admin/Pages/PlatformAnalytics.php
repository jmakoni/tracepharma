<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Admin\Resources\CustomerOnboardings\CustomerOnboardingResource;
use App\Filament\Admin\Resources\DemoRequests\DemoRequestResource;
use App\Filament\Admin\Resources\Fda\FdaImportRuns\FdaImportRunResource;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\FdaOrganizationMatchReviewResource;
use App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\FdaWdd3plUnmatchedResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Admin;
use App\Support\Dashboard\AdminAnalyticsMetrics;
use App\Support\Dashboard\AdminDashboardLinks;
use App\Support\Dashboard\AdminDashboardWidgetCatalog;
use App\Support\Dashboard\ResolveAdminDashboardWidgets;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class PlatformAnalytics extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Platform Analytics';

    protected static ?string $title = 'Platform Analytics';

    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.admin.pages.platform-analytics';

    public int $rangeDays = 30;

    public static function canAccess(): bool
    {
        return auth('admin')->user() instanceof Admin;
    }

    public function mount(): void
    {
        $this->rangeDays = $this->rangeDays === 7 ? 7 : 30;
    }

    public function updatedRangeDays(mixed $value): void
    {
        $this->rangeDays = ((int) $value) === 7 ? 7 : 30;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Platform-wide trends for tenants, onboarding, imports, and hub coverage.';
    }

    public function asOfLabel(): string
    {
        return now()->timezone((string) config('app.timezone'))->toDayDateTimeString();
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     data: array<string, mixed>,
     *     url: string|null,
     *     url_label: string|null
     * }>
     */
    public function widgets(): array
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            return [];
        }

        $keys = ResolveAdminDashboardWidgets::make()->forAnalyticsPage($admin);
        $metrics = AdminAnalyticsMetrics::make($this->rangeDays);
        $widgets = [];

        foreach ($keys as $key) {
            $definition = AdminDashboardWidgetCatalog::definition($key);
            if ($definition === null) {
                continue;
            }

            $widgets[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'data' => $metrics->forKey($key),
                'url' => $this->drillUrl($key),
                'url_label' => $this->drillLabel($key),
            ];
        }

        return $widgets;
    }

    private function drillUrl(string $key): ?string
    {
        return match ($key) {
            'tenant_growth' => $this->resourceIndexUrl(TenantResource::class),
            'onboarding_funnel' => $this->resourceIndexUrl(CustomerOnboardingResource::class),
            'demo_volume' => $this->resourceIndexUrl(DemoRequestResource::class),
            'import_trends' => $this->resourceIndexUrl(FdaImportRunResource::class),
            'unmatched_aging' => $this->resourceIndexUrl(FdaWdd3plUnmatchedResource::class),
            'match_review_aging' => $this->resourceIndexUrl(FdaOrganizationMatchReviewResource::class),
            'hub_coverage' => AdminDashboardLinks::pageUrl(EpcisHubSettings::class),
            'activity_volume' => $this->resourceIndexUrl(ActivityLogResource::class),
            default => null,
        };
    }

    private function drillLabel(string $key): ?string
    {
        return match ($key) {
            'tenant_growth' => 'View tenants',
            'onboarding_funnel' => 'View onboardings',
            'demo_volume' => 'View demo requests',
            'import_trends' => 'View import runs',
            'unmatched_aging' => 'View unmatched',
            'match_review_aging' => 'View match reviews',
            'hub_coverage' => 'View hub settings',
            'activity_volume' => 'View activity',
            default => null,
        };
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function resourceIndexUrl(string $resource): ?string
    {
        return AdminDashboardLinks::resourceIndexUrl($resource);
    }

    public static function getDocumentation(): array|string
    {
        return 'platform.analytics';
    }
}
