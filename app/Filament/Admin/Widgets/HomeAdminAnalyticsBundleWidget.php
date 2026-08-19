<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\PlatformAnalytics;
use App\Models\Admin;
use App\Support\Dashboard\AdminAnalyticsMetrics;
use App\Support\Dashboard\AdminDashboardLinks;
use App\Support\Dashboard\AdminDashboardWidgetCatalog;
use App\Support\Dashboard\ResolveAdminDashboardWidgets;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\View;
use Throwable;

class HomeAdminAnalyticsBundleWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.home-admin-analytics-bundle-widget';

    public static function canView(): bool
    {
        $admin = auth('admin')->user();

        if (! $admin instanceof Admin) {
            return false;
        }

        return self::analyticsKeysForAdmin($admin) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $admin = auth('admin')->user();
        $keys = $admin instanceof Admin ? self::analyticsKeysForAdmin($admin) : [];
        $metrics = $admin instanceof Admin
            ? AdminAnalyticsMetrics::make(7)
            : null;

        $widgets = [];

        foreach ($keys as $key) {
            $definition = AdminDashboardWidgetCatalog::definition($key);

            if ($definition === null) {
                continue;
            }

            $viewName = 'filament.admin.pages.partials.analytics.'.$key;
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
            'analyticsUrl' => AdminDashboardLinks::pageUrl(PlatformAnalytics::class),
            'asOf' => now()->timezone((string) config('app.timezone'))->format('g:i A'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function analyticsKeysForAdmin(Admin $admin): array
    {
        $lookup = array_flip(AdminDashboardWidgetCatalog::analyticsKeys());

        return array_values(array_filter(
            ResolveAdminDashboardWidgets::make()->forAdmin($admin),
            fn (string $key): bool => isset($lookup[$key]),
        ));
    }
}
