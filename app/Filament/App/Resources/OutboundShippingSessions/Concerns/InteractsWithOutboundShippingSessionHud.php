<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Concerns;

use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Filament\App\Resources\OutboundShippingSessions\RelationManagers\ScanLinesRelationManager;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Models\Epcis\Epc;
use App\Models\Shipping\OutboundShippingSession;
use App\Support\Gs1\ElementString;
use App\Support\Shipping\DetectOpenParentHierarchyOnShip;
use App\Support\Shipping\ShipLayout;
use App\Support\TenantFeatures;
use App\Support\Tracing\AssetTrackingUrl;
use App\Support\Tracing\EpcContextLinks;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use Throwable;

trait InteractsWithOutboundShippingSessionHud
{
    public ?string $scan = '';

    public ?string $lastScanMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastScanTone = null;

    public ?string $lastScanDetail = null;

    public ?string $lastScanHref = null;

    public ?int $lastScanEpcId = null;

    /** @var list<array{key: string, label: string, url: ?string, opens: bool}> */
    public array $lastScanContextLinks = [];

    #[Locked]
    public bool $confirmScanInFlight = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing(['site', 'tradingPartner', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection']);

        if ($scan = request()->query('scan')) {
            $this->scan = (string) $scan;
        }
    }

    public function canScan(): bool
    {
        return $this->getRecord()->canScan();
    }

    public function isActive(): bool
    {
        return $this->getRecord()->isActive();
    }

    public function isCompleted(): bool
    {
        return $this->getRecord()->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->getRecord()->status === 'cancelled';
    }

    /**
     * Floor and desktop complete banners must not read as "sent" when outbound
     * transmission failed or is still pending after EPCIS authoring.
     *
     * @return array{title: string, body: string, tone: 'success'|'warning'|'error'}
     */
    public function shipCompleteCopy(): array
    {
        /** @var OutboundShippingSession $session */
        $session = $this->getRecord();
        $document = $session->epcisDocument;
        $status = $document?->transmission_status;

        if ($session->shipping_events_generated_at === null) {
            return [
                'title' => 'Ship order incomplete',
                'body' => 'This order is marked complete but shipping EPCIS was not authored. Retry send from desktop.',
                'tone' => 'warning',
            ];
        }

        if ($status === 'sent') {
            return [
                'title' => 'Shipment sent',
                'body' => 'Shipping EPCIS authored and transmitted to the partner.',
                'tone' => 'success',
            ];
        }

        if ($status === 'failed') {
            $detail = filled($document?->error_message)
                ? (string) $document->error_message
                : 'Outbound transmission did not succeed.';

            return [
                'title' => 'Shipment not transmitted',
                'body' => 'Shipping EPCIS was authored, but outbound transmission failed: '.$detail,
                'tone' => 'error',
            ];
        }

        if ($status === 'skipped') {
            $detail = filled($document?->error_message)
                ? (string) $document->error_message
                : 'Outbound transmission was skipped.';

            return [
                'title' => 'Shipment not transmitted',
                'body' => 'Shipping EPCIS was authored, but outbound transmission was skipped: '.$detail,
                'tone' => 'error',
            ];
        }

        if (in_array($status, ['queued', 'sending'], true)) {
            return [
                'title' => 'Shipment authored',
                'body' => 'Shipping EPCIS authored. Outbound transmission is '.$this->transmissionStatusLabel().'.',
                'tone' => 'warning',
            ];
        }

        return [
            'title' => 'Shipment authored',
            'body' => 'Shipping EPCIS authored. Outbound transmission is pending.',
            'tone' => 'warning',
        ];
    }

    public function shipCompletePanelClass(): string
    {
        return match ($this->shipCompleteCopy()['tone']) {
            'success' => 'rounded-lg border border-success/30 bg-success/10 p-4',
            'warning' => 'rounded-lg border border-warning/30 bg-warning/10 p-4',
            default => 'rounded-lg border border-error/30 bg-error/10 p-4',
        };
    }

    public function transmissionStatusLabel(): string
    {
        return match ($this->getRecord()->epcisDocument?->transmission_status) {
            'sent' => 'Sent',
            'failed' => 'Failed',
            'queued' => 'Queued',
            'sending' => 'Sending',
            'skipped' => 'Skipped',
            default => 'Pending',
        };
    }

    public function transmissionStatusBadgeColor(): string
    {
        return match ($this->getRecord()->epcisDocument?->transmission_status) {
            'sent' => 'success',
            'failed' => 'danger',
            'queued', 'sending' => 'warning',
            default => 'gray',
        };
    }

    public function confirmedCount(): int
    {
        return (int) $this->getRecord()->confirmed_count;
    }

    public function desktopShipUrl(array $parameters = []): string
    {
        if ($scan = request()->query('scan')) {
            $parameters['scan'] = (string) $scan;
        }

        return ShipLayout::desktopUrl($this->getRecord(), $parameters);
    }

    public function floorShipUrl(array $parameters = []): string
    {
        if ($scan = request()->query('scan')) {
            $parameters['scan'] = (string) $scan;
        }

        return ShipLayout::floorUrl($this->getRecord(), $parameters);
    }

    public function confirmScanAction(): Action
    {
        return Action::make('confirmScan')
            ->label('Confirm')
            ->action(function (): void {
                if ($this->confirmScanInFlight) {
                    return;
                }

                $this->confirmScanInFlight = true;

                try {
                    /** @var OutboundShippingSession $session */
                    $session = $this->getRecord();

                    if (! $session->canScan()) {
                        $this->setLastScan('error', 'This ship order is no longer open.');

                        Notification::make()
                            ->title('Session closed')
                            ->danger()
                            ->ephemeral()->send();

                        $this->dispatch('scan-result', tone: 'error');

                        return;
                    }

                    $scan = ElementString::normalize(trim((string) $this->scan));
                    $this->scan = $scan;

                    if ($scan === '') {
                        $this->setLastScan('error', 'Scan an SSCC or SGTIN to confirm.');

                        Notification::make()
                            ->title('Scan required')
                            ->danger()
                            ->ephemeral()->send();

                        $this->dispatch('focus-scan');
                        $this->dispatch('scan-result', tone: 'error');

                        return;
                    }

                    $result = app(ConfirmOutboundShippingScan::class)->handle(
                        $session,
                        $scan,
                        auth()->id(),
                    );

                    $tone = match ($result['effect']) {
                        'already_confirmed' => 'warn',
                        'confirmed' => 'ok',
                        default => 'error',
                    };

                    $this->scan = '';
                    $this->getRecord()->refresh()->loadMissing(['site', 'tradingPartner', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection']);

                    $this->setLastScan(
                        $tone,
                        $result['message'],
                        $this->identifierFor($result['epc']),
                        AssetTrackingUrl::forEpc($result['epc'] ?? null),
                        $result['epc'] ?? null,
                    );

                    $notification = Notification::make()->title($result['message']);

                    match ($tone) {
                        'ok' => $notification->success(),
                        'warn' => $notification->warning(),
                        default => $notification->danger(),
                    };

                    $notification->ephemeral()->send();

                    if ($result['effect'] === 'confirmed') {
                        $this->notifyOpenParentHierarchyIfNeeded($this->getRecord());
                    }

                    $this->dispatch('focus-scan');
                    $this->dispatch('scan-result', tone: $tone);
                    $this->dispatch('outbound-shipping-scan-lines-updated')
                        ->to(ScanLinesRelationManager::class);
                } finally {
                    $this->confirmScanInFlight = false;
                }
            });
    }

    public function stageScan(?string $raw = null): void
    {
        if ($this->confirmScanInFlight) {
            return;
        }

        if ($raw !== null) {
            $this->scan = ElementString::normalize(trim($raw));
        }

        $this->mountAction('confirmScan');
    }

    protected function notifyOpenParentHierarchyIfNeeded(OutboundShippingSession $session): void
    {
        $detection = app(DetectOpenParentHierarchyOnShip::class)->handle($session);
        if (! ($detection['unexpected'] ?? false)) {
            return;
        }

        $notification = Notification::make()
            ->title('Open parent hierarchy detected — verify packing on SSCC Labels')
            ->body(sprintf(
                '%d confirmed line(s) still have an open aggregation parent that is not on this ship order.',
                count($detection['affected_child_epc_ids'] ?? []),
            ))
            ->warning();

        $ssccLabelsUrl = $this->ssccLabelsListUrl();
        if ($ssccLabelsUrl !== null) {
            $notification->actions([
                Action::make('openSsccLabels')
                    ->label('SSCC Labels')
                    ->url($ssccLabelsUrl)
                    ->openUrlInNewTab(),
            ]);
        }

        $notification->ephemeral()->send();
    }

    private function setLastScan(
        string $tone,
        string $message,
        ?string $detail = null,
        ?string $href = null,
        ?Epc $epc = null,
    ): void {
        $this->lastScanTone = $tone;
        $this->lastScanMessage = $message;
        $this->lastScanDetail = $detail;
        $this->lastScanHref = $href;
        $this->lastScanEpcId = $epc?->getKey();
        $this->lastScanContextLinks = $epc !== null
            ? array_values(array_filter(
                app(EpcContextLinks::class)->forEpc($epc, AssetTrackingUrl::scanForEpc($epc), auth()->id()),
                fn (array $link): bool => ($link['key'] ?? null) !== 'open_ship',
            ))
            : [];
    }

    private function identifierFor(?Epc $epc): ?string
    {
        if ($epc === null) {
            return null;
        }

        if (filled($epc->sscc18)) {
            return $epc->sscc18;
        }

        if (filled($epc->gtin14)) {
            return $epc->gtin14.(filled($epc->serial_number) ? ' / '.$epc->serial_number : '');
        }

        return null;
    }

    private function ssccLabelsListUrl(): ?string
    {
        if (! TenantFeatures::forTenant(tenant())->supportsSsccLabeling()) {
            return null;
        }

        try {
            return SsccLabelResource::getUrl('index', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
