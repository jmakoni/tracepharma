<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Fda\FdaEstablishments\FdaEstablishmentResource;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use App\Filament\Admin\Resources\Fda\FdaProducts\FdaProductResource;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\FdaWddFacilityResource;
use App\Filament\Admin\Resources\Fda\FdaWddLicenses\FdaWddLicenseResource;
use App\Filament\Admin\Widgets\Concerns\AuthorizesAdminDashboardWidget;
use App\Support\Dashboard\AdminDashboardLinks;
use App\Support\Dashboard\AdminDashboardMetrics;
use Filament\Widgets\Widget;

class RegistryCensusWidget extends Widget
{
    use AuthorizesAdminDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.admin.widgets.registry-census-widget';

    public static function catalogKey(): string
    {
        return 'registry_census';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = AdminDashboardMetrics::make()->registryCensus();
        $empty = $metrics['organizations'] === 0
            && $metrics['establishments'] === 0
            && $metrics['facilities'] === 0
            && $metrics['licenses'] === 0
            && $metrics['products'] === 0;

        return [
            'organizations' => $metrics['organizations'],
            'establishments' => $metrics['establishments'],
            'facilities' => $metrics['facilities'],
            'licenses' => $metrics['licenses'],
            'products' => $metrics['products'],
            'empty' => $empty,
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'organizationsUrl' => AdminDashboardLinks::resourceIndexUrl(FdaOrganizationResource::class),
            'establishmentsUrl' => AdminDashboardLinks::resourceIndexUrl(FdaEstablishmentResource::class),
            'facilitiesUrl' => AdminDashboardLinks::resourceIndexUrl(FdaWddFacilityResource::class),
            'licensesUrl' => AdminDashboardLinks::resourceIndexUrl(FdaWddLicenseResource::class),
            'productsUrl' => AdminDashboardLinks::resourceIndexUrl(FdaProductResource::class),
        ];
    }
}
