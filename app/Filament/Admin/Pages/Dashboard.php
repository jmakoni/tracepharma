<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\HomeAdminAnalyticsBundleWidget;
use App\Filament\Admin\Widgets\HubHealthWidget;
use App\Filament\Admin\Widgets\ImportHealthWidget;
use App\Filament\Admin\Widgets\OnboardingQueueWidget;
use App\Filament\Admin\Widgets\PrimaryCtasWidget;
use App\Filament\Admin\Widgets\RegistryCensusWidget;
use App\Filament\Admin\Widgets\RegistryExceptionsWidget;
use App\Filament\Admin\Widgets\TenantCensusWidget;
use App\Models\Admin;
use App\Support\Dashboard\AdminDashboardWidgetCatalog;
use App\Support\Dashboard\ResolveAdminDashboardWidgets;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

class Dashboard extends BaseDashboard
{
    /**
     * @var array<string, class-string<Widget>>
     */
    private const WIDGET_CLASSES = [
        'tenant_census' => TenantCensusWidget::class,
        'onboarding_queue' => OnboardingQueueWidget::class,
        'registry_census' => RegistryCensusWidget::class,
        'registry_exceptions' => RegistryExceptionsWidget::class,
        'import_health' => ImportHealthWidget::class,
        'hub_health' => HubHealthWidget::class,
        'primary_ctas' => PrimaryCtasWidget::class,
    ];

    public static function getNavigationItems(): array
    {
        return array_map(static function ($item) {
            return $item->extraAttributes([
                'class' => 'tp-nav-icon-only',
                'title' => __('filament-panels::pages/dashboard.title'),
                'aria-label' => __('filament-panels::pages/dashboard.title'),
            ]);
        }, parent::getNavigationItems());
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        $admin = auth('admin')->user();
        $keys = ResolveAdminDashboardWidgets::make()->forAdmin($admin instanceof Admin ? $admin : null);
        $widgets = [];
        $analyticsLookup = array_flip(AdminDashboardWidgetCatalog::analyticsKeys());
        $hasAnalytics = false;

        foreach ($keys as $key) {
            $class = self::WIDGET_CLASSES[$key] ?? null;

            if ($class !== null) {
                $widgets[] = $class;
            }

            if (isset($analyticsLookup[$key])) {
                $hasAnalytics = true;
            }
        }

        if ($hasAnalytics) {
            $widgets[] = HomeAdminAnalyticsBundleWidget::class;
        }

        return $widgets;
    }
}
