<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\EpcisHubSettings;
use App\Filament\Admin\Pages\PlatformAnalytics;
use App\Filament\Admin\Resources\CustomerOnboardings\CustomerOnboardingResource;
use App\Filament\Admin\Resources\Fda\FdaImportRuns\FdaImportRunResource;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\FdaOrganizationMatchReviewResource;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Widgets\Concerns\AuthorizesAdminDashboardWidget;
use App\Support\Dashboard\AdminDashboardLinks;
use Filament\Widgets\Widget;

class PrimaryCtasWidget extends Widget
{
    use AuthorizesAdminDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.primary-ctas-widget';

    public static function catalogKey(): string
    {
        return 'primary_ctas';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $actions = array_values(array_filter([
            $this->action('Tenants', AdminDashboardLinks::resourceIndexUrl(TenantResource::class), true),
            $this->action('Customer onboarding', AdminDashboardLinks::resourceIndexUrl(CustomerOnboardingResource::class), false),
            $this->action('Import runs', AdminDashboardLinks::resourceIndexUrl(FdaImportRunResource::class), false),
            $this->action('Match reviews', AdminDashboardLinks::resourceIndexUrl(FdaOrganizationMatchReviewResource::class), false),
            $this->action('Organizations', AdminDashboardLinks::resourceIndexUrl(FdaOrganizationResource::class), false),
            $this->action('EPCIS Hub', AdminDashboardLinks::pageUrl(EpcisHubSettings::class), false),
            $this->action('Analytics', AdminDashboardLinks::pageUrl(PlatformAnalytics::class), false),
        ]));

        return [
            'actions' => $actions,
            'asOf' => now()->timezone((string) config('app.timezone'))->format('g:i A'),
        ];
    }

    /**
     * @return array{label: string, url: string, primary: bool}|null
     */
    private function action(string $label, ?string $url, bool $primary): ?array
    {
        if ($url === null) {
            return null;
        }

        return [
            'label' => $label,
            'url' => $url,
            'primary' => $primary,
        ];
    }
}
