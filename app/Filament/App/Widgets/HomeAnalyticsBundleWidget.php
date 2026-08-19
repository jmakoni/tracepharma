<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\Analytics;
use App\Filament\App\Resources\Sites\SiteResource;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Filament\App\Resources\TracingRequests\TracingRequestResource;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Dashboard\AnalyticsMetrics;
use App\Support\Dashboard\DashboardLinks;
use App\Support\Dashboard\DashboardWidgetCatalog;
use App\Support\Dashboard\ResolveDashboardWidgets;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\View;
use Throwable;

class HomeAnalyticsBundleWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.app.widgets.home-analytics-bundle-widget';

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return self::analyticsKeysForUser($user) !== [];
    }

    public function tracingRequestUrl(int $id): ?string
    {
        return DashboardLinks::resourceViewUrl(TracingRequestResource::class, $id);
    }

    public function siteUrl(?int $id): ?string
    {
        return $id === null
            ? DashboardLinks::resourceIndexUrl(SiteResource::class)
            : DashboardLinks::resourceViewUrl(SiteResource::class, $id);
    }

    public function partnerUrl(int $id): ?string
    {
        return DashboardLinks::resourceViewUrl(TradingPartnerResource::class, $id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $keys = $user instanceof User ? self::analyticsKeysForUser($user) : [];
        $metrics = $user instanceof User
            ? AnalyticsMetrics::make($user, 7, CurrentSite::id())
            : null;

        $widgets = [];

        foreach ($keys as $key) {
            $definition = DashboardWidgetCatalog::definition($key);

            if ($definition === null) {
                continue;
            }

            $viewName = 'filament.app.pages.partials.analytics.'.$key;
            $data = [];
            $compatible = View::exists($viewName);

            if ($compatible && $metrics !== null) {
                try {
                    $data = $metrics->forKey($key);
                } catch (Throwable) {
                    $compatible = false;
                    $data = [];
                }
            }

            $widgets[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'data' => $data,
                'view' => $viewName,
                'compatible' => $compatible,
            ];
        }

        return [
            'widgets' => $widgets,
            'analyticsUrl' => DashboardLinks::pageUrl(Analytics::class),
            'asOf' => now()->timezone((string) config('app.timezone'))->format('g:i A'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function analyticsKeysForUser(User $user): array
    {
        $lookup = array_flip(DashboardWidgetCatalog::analyticsKeys());

        return array_values(array_filter(
            ResolveDashboardWidgets::make()->forUser($user),
            fn (string $key): bool => isset($lookup[$key]),
        ));
    }
}
