<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\Analytics;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\VerifyProduct;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Support\Dashboard\DashboardLinks;
use Filament\Widgets\Widget;

class PrimaryCtasWidget extends Widget
{
    use AuthorizesDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.app.widgets.primary-ctas-widget';

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
            $this->action('Receive', DashboardLinks::resourceIndexUrl(ReceivingSessionResource::class), true),
            $this->action('Ship Order', DashboardLinks::resourceIndexUrl(OutboundShippingSessionResource::class), false),
            $this->action('Verify', DashboardLinks::pageUrl(VerifyProduct::class), false),
            $this->action('Operations Hub', DashboardLinks::pageUrl(OperationsHub::class), false),
            $this->action('Analytics', DashboardLinks::pageUrl(Analytics::class), false),
        ]));

        return [
            'actions' => $actions,
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
