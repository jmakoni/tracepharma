<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Pages;

use App\Filament\App\Pages\ScanOutWorkstation;
use App\Filament\App\Resources\OutboundShippingSessions\Concerns\InteractsWithOutboundShippingSessionHud;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Scan-only floor ship (phone/tablet). Desktop wizard remains {@see ViewOutboundShippingSession}.
 */
class MobileViewOutboundShippingSession extends ViewRecord
{
    use InteractsWithOutboundShippingSessionHud;

    protected static string $resource = OutboundShippingSessionResource::class;

    protected string $view = 'filament.app.resources.outbound-shipping-sessions.pages.mobile-view-outbound-shipping-session';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-ship-page',
    ];

    public function content(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function routeDisplayLabel(): string
    {
        /** @var OutboundShippingSession $record */
        $record = $this->getRecord();

        $site = $record->site?->name;
        $customer = $record->tradingPartner?->name;

        if (filled($site) && filled($customer)) {
            return $site.' → '.$customer;
        }

        if (filled($site)) {
            return $site;
        }

        return 'Ship order #'.$record->getKey();
    }

    public function shippingListUrl(): string
    {
        return OutboundShippingSessionResource::getUrl(name: 'index', panel: 'app');
    }

    public function scanOutDeskUrl(): string
    {
        return ScanOutWorkstation::urlForSession((int) $this->getRecord()->getKey(), ['step' => 2]);
    }

    public function cartBadgeCount(): int
    {
        return $this->confirmedCount();
    }

    public function recentScansCaption(): ?string
    {
        $total = $this->confirmedCount();

        if ($total <= 8) {
            return null;
        }

        return 'Showing last 8 of '.$total;
    }

    /**
     * @return Collection<int, OutboundShippingScanLine>
     */
    public function recentScanLines(): Collection
    {
        return OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $this->getRecord()->getKey())
            ->where('status', 'confirmed')
            ->select([
                'id',
                'outbound_shipping_session_id',
                'epc_id',
                'status',
                'scan_raw',
                'confirmed_at',
            ])
            ->with([
                'epc:id,epc_uri,sscc18,gtin14,serial_number',
            ])
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    public function recentScanLineLabel(OutboundShippingScanLine $line): string
    {
        $epc = $line->epc;

        if ($epc !== null) {
            if (filled($epc->sscc18)) {
                return (string) $epc->sscc18;
            }

            if (filled($epc->gtin14)) {
                return (string) $epc->gtin14.(filled($epc->serial_number) ? ' / '.$epc->serial_number : '');
            }

            if (filled($epc->epc_uri)) {
                return (string) $epc->epc_uri;
            }
        }

        return filled($line->scan_raw) ? (string) $line->scan_raw : 'Scan #'.$line->getKey();
    }
}
