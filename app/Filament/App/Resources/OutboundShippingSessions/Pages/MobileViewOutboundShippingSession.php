<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Pages;

use App\Actions\Shipping\DeleteOutboundShippingSession;
use App\Actions\Shipping\UnconfirmOutboundShippingScanLine;
use App\Filament\App\Pages\ScanOutWorkstation;
use App\Filament\App\Resources\OutboundShippingSessions\Concerns\InteractsWithOutboundShippingSessionHud;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\Notifications\Notification;
use App\Filament\Support\Floor\UnsubmittedSessionDeleteAction;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use DomainException;
use Filament\Actions\Action;
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

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            UnsubmittedSessionDeleteAction::forShipping(
                fn (OutboundShippingSession $record) => app(DeleteOutboundShippingSession::class)->handle($record, auth()->id()),
                OutboundShippingSessionResource::getUrl(name: 'index', panel: 'app'),
            ),
        ];
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

    public function canRemoveRecentScanLine(OutboundShippingScanLine $line): bool
    {
        /** @var OutboundShippingSession $session */
        $session = $this->getRecord();

        if (! $session->canUnconfirmScanLines()) {
            return false;
        }

        return $line->status === 'confirmed';
    }

    public function removeRecentScanLine(int $lineId): void
    {
        /** @var OutboundShippingSession $session */
        $session = $this->getRecord();

        $line = OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->whereKey($lineId)
            ->first();

        if ($line === null || ! $this->canRemoveRecentScanLine($line)) {
            Notification::make()
                ->title('Remove blocked')
                ->body('This scan cannot be removed.')
                ->danger()
                ->send();

            return;
        }

        try {
            app(UnconfirmOutboundShippingScanLine::class)->handle($line, auth()->id());
        } catch (DomainException $e) {
            Notification::make()
                ->title('Remove blocked')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->getRecord()->refresh();
        $this->refreshOutboundShippingSessionHud();

        Notification::make()
            ->title('Scan removed')
            ->success()
            ->send();
    }
}
