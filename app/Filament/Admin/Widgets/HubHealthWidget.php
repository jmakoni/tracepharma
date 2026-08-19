<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\EpcisHubSettings;
use App\Filament\Admin\Widgets\Concerns\AuthorizesAdminDashboardWidget;
use App\Support\Dashboard\AdminDashboardLinks;
use App\Support\Dashboard\AdminDashboardMetrics;
use Filament\Widgets\Widget;

class HubHealthWidget extends Widget
{
    use AuthorizesAdminDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.admin.widgets.hub-health-widget';

    public static function catalogKey(): string
    {
        return 'hub_health';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = AdminDashboardMetrics::make()->hubHealth();
        $empty = $metrics['environments'] === [] && $metrics['active_routes'] === 0
            && ($metrics['aggregation_link_fk_drift']['count'] ?? 0) === 0;

        return [
            'environments' => $metrics['environments'],
            'activeRoutes' => $metrics['active_routes'],
            'aggregationLinkFkDriftCount' => $metrics['aggregation_link_fk_drift']['count'],
            'aggregationLinkFkCheckedAt' => $metrics['aggregation_link_fk_drift']['checked_at']
                ?->timezone(config('app.timezone'))
                ->format('g:i A'),
            'empty' => $empty,
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'hubUrl' => AdminDashboardLinks::pageUrl(EpcisHubSettings::class),
        ];
    }
}
