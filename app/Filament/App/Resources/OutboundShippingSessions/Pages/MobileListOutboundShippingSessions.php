<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Pages;

use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Models\Shipping\OutboundShippingSession;
use App\Support\Shipping\ShipLayout;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

class MobileListOutboundShippingSessions extends Page
{
    protected static string $resource = OutboundShippingSessionResource::class;

    protected string $view = 'filament.app.resources.outbound-shipping-sessions.pages.mobile-list-outbound-shipping-sessions';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-shell-page tp-floor-list-page tp-floor-ship-list-page',
    ];

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @return Collection<int, OutboundShippingSession>
     */
    public function sessions(): Collection
    {
        return OutboundShippingSessionResource::getEloquentQuery()
            ->with(['site', 'tradingPartner'])
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function floorSessionUrl(OutboundShippingSession $session): string
    {
        return ShipLayout::floorUrl($session);
    }

    public function desktopListUrl(): string
    {
        return OutboundShippingSessionResource::getUrl('index', panel: 'app');
    }

    public function floorListUrl(): string
    {
        return OutboundShippingSessionResource::getUrl('list-floor', panel: 'app');
    }

    public function createUrl(): string
    {
        return OutboundShippingSessionResource::getUrl('create', panel: 'app');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
