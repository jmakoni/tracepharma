<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\IntegrationHealth;
use App\Filament\App\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Support\Dashboard\DashboardLinks;
use App\Support\Dashboard\DashboardMetrics;
use App\Support\TenantFeatures;
use Filament\Widgets\Widget;

class IntegrationHealthWidget extends Widget
{
    use AuthorizesDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.app.widgets.integration-health-widget';

    public static function catalogKey(): string
    {
        return 'integration_health';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = DashboardMetrics::make(auth()->user())->integrationHealth();
        $features = TenantFeatures::forTenant(tenant());

        return [
            'inboundErrors' => $metrics['inbound_errors'],
            'outboundFailed' => $metrics['outbound_failed'],
            'showInbound' => $features->supportsInboundIntegrations(),
            'showOutbound' => $features->supportsOutboundIntegrations(),
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'healthUrl' => DashboardLinks::pageUrl(IntegrationHealth::class),
        ];
    }
}
