<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Widgets\Concerns\AuthorizesAdminDashboardWidget;
use App\Support\Dashboard\AdminDashboardLinks;
use App\Support\Dashboard\AdminDashboardMetrics;
use Filament\Widgets\Widget;

class TenantCensusWidget extends Widget
{
    use AuthorizesAdminDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.admin.widgets.tenant-census-widget';

    public static function catalogKey(): string
    {
        return 'tenant_census';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = AdminDashboardMetrics::make()->tenantCensus();

        return [
            'total' => $metrics['total'],
            'byProfile' => $metrics['by_profile'],
            'byStatus' => $metrics['by_status'],
            'empty' => $metrics['total'] === 0,
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'tenantsUrl' => AdminDashboardLinks::resourceIndexUrl(TenantResource::class),
        ];
    }
}
