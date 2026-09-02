<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Concerns;

use App\Actions\Shipping\CompleteOutboundShippingSession;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\OutboundConnection;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Shipping\OutboundPortalPickupNotice;
use App\Support\Shipping\OutboundShipReadiness;
use App\Support\Shipping\SearchShipToCustomers;
use DomainException;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Livewire\Attributes\Url;

trait InteractsWithOutboundShippingWizard
{
    #[Url(as: 'step')]
    public int $wizardStep = 1;

    public ?int $trading_partner_id = null;

    public ?int $ship_to_site_id = null;

    public ?string $ship_to_gln = null;

    public string $customerSearch = '';

    /** @var list<array{site_id: int, trading_partner_id: int, company: string, address: string, gln: ?string, site_name: string}> */
    public array $customerSuggestions = [];

    public bool $customerDropdownOpen = false;

    public ?int $outbound_connection_id = null;

    public ?string $asn_number = null;

    public ?string $customer_po = null;

    public ?string $invoice_number = null;

    public ?string $shipment_reference = null;

    public ?int $expected_count = null;

    public bool $dscsa_affirm = false;

    public bool $is_drop_shipment = false;

    abstract protected function getOutboundShippingSession(): OutboundShippingSession;

    abstract protected function refreshOutboundShippingSession(): void;

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function readinessBadges(): array
    {
        $record = $this->getOutboundShippingSession();

        if (! in_array($record->status, ['open', 'in_progress'], true)) {
            return [];
        }

        $draft = $record->replicate();
        $draft->setRelation('tradingPartner', $record->tradingPartner);
        $draft->setRelation('shipToSite', $record->shipToSite);
        $draft->forceFill([
            'trading_partner_id' => $this->trading_partner_id,
            'ship_to_site_id' => $this->ship_to_site_id,
            'ship_to_gln' => $this->ship_to_gln,
            'outbound_connection_id' => $this->outbound_connection_id,
            'asn_number' => $this->asn_number ?? $record->asn_number,
            'customer_po' => $this->customer_po ?? $record->customer_po,
            'invoice_number' => $this->invoice_number ?? $record->invoice_number,
            'dscsa_affirm' => $this->dscsa_affirm ?? $record->dscsa_affirm,
        ]);

        if ($this->ship_to_site_id !== null) {
            $draft->setRelation(
                'shipToSite',
                Site::query()->with('tradingPartner')->find($this->ship_to_site_id),
            );
        }

        if ($this->trading_partner_id !== null) {
            $draft->setRelation(
                'tradingPartner',
                TradingPartner::query()->find($this->trading_partner_id),
            );
        }

        return app(OutboundShipReadiness::class)->badges($draft);
    }

    public function expectedCountGateRequiresPositiveQuantity(): bool
    {
        return $this->getOutboundShippingSession()->outboundConnection?->conformanceState()?->requiresExpectedQuantity() === true;
    }

    public function goToStep(int $step): void
    {
        if ($step >= 1 && $step <= 3) {
            $this->wizardStep = $step;
        }
    }

    public function wizardShowsCustomerNudge(): bool
    {
        if ($this->wizardStep !== 1) {
            return false;
        }

        $session = $this->getOutboundShippingSession();

        if ((int) $session->confirmed_count <= 0) {
            return false;
        }

        return $this->trading_partner_id === null && $this->ship_to_site_id === null && blank($this->ship_to_gln);
    }

    public function savePartyAction(): Action
    {
        return Action::make('saveParty')
            ->label('Save customer')
            ->action(function (): void {
                $session = $this->getOutboundShippingSession();

                try {
                    app(UpdateOutboundShippingParty::class)->handle($session, [
                        'trading_partner_id' => $this->trading_partner_id,
                        'ship_to_site_id' => $this->ship_to_site_id,
                        'ship_to_gln' => $this->ship_to_gln,
                        'outbound_connection_id' => $this->outbound_connection_id,
                    ]);
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Save blocked')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $this->refreshOutboundShippingSession();
                $this->hydrateWizardFromRecord();

                Notification::make()
                    ->title('Customer saved')
                    ->success()
                    ->send();
            });
    }

    public function saveReferencesAction(): Action
    {
        return Action::make('saveReferences')
            ->label('Save references')
            ->action(function (): void {
                $session = $this->getOutboundShippingSession();

                try {
                    app(UpdateOutboundShippingReferences::class)->handle($session, [
                        'asn_number' => $this->asn_number,
                        'customer_po' => $this->customer_po,
                        'invoice_number' => $this->invoice_number,
                        'shipment_reference' => $this->shipment_reference,
                        'dscsa_affirm' => $this->dscsa_affirm,
                        'is_drop_shipment' => $this->is_drop_shipment,
                        'expected_count' => $this->expected_count ?? 0,
                    ]);
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Save blocked')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $this->refreshOutboundShippingSession();
                $this->hydrateWizardFromRecord();

                Notification::make()
                    ->title('References saved')
                    ->success()
                    ->send();
            });
    }

    public function updatedCustomerSearch(string $value): void
    {
        $this->customerSuggestions = app(SearchShipToCustomers::class)->handle($value);
        $this->customerDropdownOpen = $this->customerSuggestions !== [];
    }

    public function openCustomerDropdown(): void
    {
        $this->customerSuggestions = app(SearchShipToCustomers::class)->handle('');
        $this->customerDropdownOpen = $this->customerSuggestions !== [];
    }

    public function selectShipToCustomer(int $siteId): void
    {
        $site = Site::query()
            ->with(['tradingPartner:id,name'])
            ->whereKey($siteId)
            ->where('is_active', true)
            ->first();

        if ($site === null || $site->trading_partner_id === null) {
            return;
        }

        $this->trading_partner_id = (int) $site->trading_partner_id;
        $this->ship_to_site_id = (int) $site->getKey();
        $this->ship_to_gln = filled($site->gln) ? (string) $site->gln : null;
        $this->outbound_connection_id = null;
        $this->customerSearch = (string) ($site->tradingPartner?->name ?: $site->name);
        $this->customerSuggestions = [];
        $this->customerDropdownOpen = false;
    }

    public function clearShipToCustomer(): void
    {
        $this->trading_partner_id = null;
        $this->ship_to_site_id = null;
        $this->ship_to_gln = null;
        $this->outbound_connection_id = null;
        $this->customerSearch = '';
        $this->customerSuggestions = [];
        $this->customerDropdownOpen = false;
    }

    /**
     * @return array{company: string, address: string}|null
     */
    public function selectedShipToSummary(): ?array
    {
        if ($this->ship_to_site_id === null) {
            return null;
        }

        $site = Site::query()
            ->with(['tradingPartner:id,name'])
            ->whereKey($this->ship_to_site_id)
            ->first();

        if ($site === null) {
            return null;
        }

        return [
            'company' => (string) ($site->tradingPartner?->name ?: $site->name),
            'address' => SearchShipToCustomers::formatAddress($site),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function outboundConnectionOptions(): array
    {
        $query = OutboundConnection::query()
            ->where('is_active', true);

        if ($this->trading_partner_id !== null) {
            $partnerId = (int) $this->trading_partner_id;

            $query->where(function ($builder) use ($partnerId): void {
                $builder->whereNull('trading_partner_id')
                    ->orWhere('trading_partner_id', $partnerId);
            });
        } else {
            $query->whereNull('trading_partner_id');
        }

        return $query
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function sendShipmentMissingRequiredRefs(): bool
    {
        return blank($this->asn_number)
            || (blank($this->customer_po) && blank($this->invoice_number))
            || ! $this->dscsa_affirm;
    }

    public function sendShipmentAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('sendShipment')
                ->label('Send shipment')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('success')
                ->visible(function (): bool {
                    try {
                        $session = $this->getOutboundShippingSession();
                    } catch (\RuntimeException) {
                        return false;
                    }

                    return $session->canSend() || $session->needsShippingEpcis();
                })
                ->disabled(fn (): bool => $this->sendShipmentMissingRequiredRefs())
                ->requiresConfirmation()
                ->modalHeading('Send this shipment?')
                ->modalDescription('Authors the shipping EPCIS document and schedules outbound transmission to the partner.')
                ->modalSubmitActionLabel('Send shipment')
                ->action(function (): void {
                    $session = $this->getOutboundShippingSession();

                    try {
                        if (blank($this->asn_number) || (blank($this->customer_po) && blank($this->invoice_number))) {
                            throw new DomainException(
                                blank($this->asn_number)
                                    ? 'ASN number is required.'
                                    : 'Customer PO or invoice number is required.',
                            );
                        }

                        if (! $this->dscsa_affirm) {
                            throw new DomainException('TI/TS affirmation is required.');
                        }

                        app(UpdateOutboundShippingReferences::class)->handle($session, [
                            'asn_number' => $this->asn_number,
                            'customer_po' => $this->customer_po,
                            'invoice_number' => $this->invoice_number,
                            'shipment_reference' => $this->shipment_reference,
                            'dscsa_affirm' => $this->dscsa_affirm,
                            'is_drop_shipment' => $this->is_drop_shipment,
                            'expected_count' => $this->expected_count ?? 0,
                        ]);
                        app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
                            'trading_partner_id' => $this->trading_partner_id,
                            'ship_to_site_id' => $this->ship_to_site_id,
                            'ship_to_gln' => $this->ship_to_gln,
                            'outbound_connection_id' => $this->outbound_connection_id,
                        ]);
                        app(CompleteOutboundShippingSession::class)->handle($session->fresh(), auth()->id());
                    } catch (AuthorizationException|InvalidArgumentException|DomainException $e) {
                        $this->refreshOutboundShippingSession();
                        $this->hydrateWizardFromRecord();

                        Notification::make()
                            ->title('Send blocked')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshOutboundShippingSession();
                    $this->hydrateWizardFromRecord();
                    $this->wizardStep = 3;

                    $copy = $this->shipCompleteCopy();
                    $portalUrl = OutboundPortalPickupNotice::signedUrl($this->getOutboundShippingSession());
                    $body = $copy['body'];
                    if ($portalUrl !== null) {
                        $body .= "\n\nCustomer portal: ".$portalUrl;
                    }
                    $notification = Notification::make()
                        ->title($copy['title'])
                        ->body($body);

                    match ($copy['tone']) {
                        'success' => $notification->success(),
                        'warning' => $notification->warning(),
                        default => $notification->danger(),
                    };

                    $notification->send();

                    $this->afterOutboundShipmentSent();
                }),
            'outbound_shipping_send',
            requireReason: false,
        );
    }

    protected function hydrateWizardFromRecord(): void
    {
        $record = $this->getOutboundShippingSession();

        $this->trading_partner_id = $record->trading_partner_id !== null ? (int) $record->trading_partner_id : null;
        $this->ship_to_site_id = $record->ship_to_site_id !== null ? (int) $record->ship_to_site_id : null;
        $this->ship_to_gln = $record->ship_to_gln;
        $this->outbound_connection_id = $record->outbound_connection_id !== null ? (int) $record->outbound_connection_id : null;
        $this->asn_number = $record->asn_number;
        $this->customer_po = $record->customer_po;
        $this->invoice_number = $record->invoice_number;
        $this->shipment_reference = $record->shipment_reference;
        $this->expected_count = $record->expected_count !== null ? (int) $record->expected_count : null;
        $this->dscsa_affirm = (bool) $record->dscsa_affirm;
        $this->is_drop_shipment = (bool) $record->is_drop_shipment;

        $summary = $this->selectedShipToSummary();
        $this->customerSearch = $summary['company']
            ?? (string) ($record->tradingPartner?->name ?? '');
        $this->customerSuggestions = [];
        $this->customerDropdownOpen = false;
    }

    protected function afterOutboundShipmentSent(): void
    {
        // Optional hook for pages that need post-send side effects.
    }
}
