<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Widgets\Concerns\AuthorizesDashboardWidget;
use App\Support\Dashboard\DashboardLinks;
use App\Support\Dashboard\DashboardMetrics;
use Filament\Widgets\Widget;

class FloorQueueWidget extends Widget
{
    use AuthorizesDashboardWidget;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.app.widgets.floor-queue-widget';

    public static function catalogKey(): string
    {
        return 'floor_queue';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $metrics = DashboardMetrics::make(auth()->user())->floorQueue();
        $empty = $metrics['receiving_open'] === 0 && $metrics['shipping_open'] === 0;

        return [
            'receivingOpen' => $metrics['receiving_open'],
            'shippingOpen' => $metrics['shipping_open'],
            'siteSelected' => $metrics['site_id'] !== null,
            'empty' => $empty,
            'asOf' => $metrics['as_of']->timezone(config('app.timezone'))->format('g:i A'),
            'receiveUrl' => DashboardLinks::resourceIndexUrl(ReceivingSessionResource::class),
            'shipUrl' => DashboardLinks::resourceIndexUrl(OutboundShippingSessionResource::class),
            'hubUrl' => DashboardLinks::pageUrl(OperationsHub::class),
        ];
    }
}
