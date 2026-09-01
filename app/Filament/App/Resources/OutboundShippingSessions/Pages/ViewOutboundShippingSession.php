<?php

namespace App\Filament\App\Resources\OutboundShippingSessions\Pages;

use App\Actions\Shipping\AddOutboundShippingEpcsFromReceivingSession;
use App\Actions\Shipping\CancelOutboundShippingSession;
use App\Actions\Shipping\CompleteOutboundShippingSession;
use App\Actions\Shipping\DeclareOutboundShippingSplit;
use App\Actions\Shipping\DeleteOutboundShippingSession;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\OverrideOutboundShippingQuantityGate;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Filament\App\Resources\OutboundShippingSessions\Concerns\InteractsWithOutboundShippingSessionHud;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\OutboundShippingSessions\RelationManagers\ScanLinesRelationManager;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\Support\Floor\UnsubmittedSessionDeleteAction;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\EpcisDocument;
use App\Models\OutboundConnection;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use App\Support\Shipping\AtpGateBypass;
use App\Support\Shipping\OutboundPortalPickupNotice;
use App\Support\Shipping\OutboundShippingSessionStatus;
use App\Support\Shipping\OutboundShipReadiness;
use App\Support\Shipping\SearchShipToCustomers;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;

class ViewOutboundShippingSession extends ViewRecord
{
    use InteractsWithOutboundShippingSessionHud {
        mount as mountOutboundShippingSessionHud;
    }

    protected static string $resource = OutboundShippingSessionResource::class;

    protected string $view = 'filament.app.resources.outbound-shipping-sessions.pages.view-outbound-shipping-session';

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

    public function mount(int|string $record): void
    {
        $this->mountOutboundShippingSessionHud($record);

        $this->hydrateWizardFromRecord();

        if (request()->query('scan')) {
            $this->wizardStep = 1;
        }
    }

    public function getHeading(): string|Htmlable|null
    {
        /** @var OutboundShippingSession $record */
        $record = $this->getRecord();

        $site = $record->site?->name;
        $customer = $record->tradingPartner?->name;

        if (filled($site) && filled($customer)) {
            return 'Ship · '.$site.' → '.$customer;
        }

        if (filled($site)) {
            return 'Ship order · '.$site;
        }

        return 'Ship order #'.$record->getKey();
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function readinessBadges(): array
    {
        /** @var OutboundShippingSession $record */
        $record = $this->getRecord();

        if (! in_array($record->status, ['open', 'in_progress'], true)) {
            return [];
        }

        // Draft customer fields on the Livewire form before Save — otherwise badges
        // still say "Ship-to GLN or site required" after autocomplete selection.
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

    public function statusLabel(): string
    {
        return OutboundShippingSessionStatus::label($this->getRecord()->status);
    }

    public function isCorrective(): bool
    {
        return (bool) $this->getRecord()->is_corrective;
    }

    public function canAccessShipFromSite(): bool
    {
        $user = auth()->user();
        $siteId = $this->getRecord()->site_id;

        if (! $user instanceof User || $siteId === null) {
            return false;
        }

        return SiteAccess::canAccessSite($user, (int) $siteId);
    }

    public function correctiveReason(): ?string
    {
        $reason = $this->getRecord()->corrective_reason;

        return filled($reason) ? (string) $reason : null;
    }

    /**
     * The outbound ATP gate is a compliance kill-switch. While it is down, a send is not
     * checked against the destination's ATP license, so the operator has to see that this
     * shipment is going out unverified rather than read the absence of a blocker as proof
     * the customer is licensed.
     */
    public function atpOutboundGateDisabled(): bool
    {
        return AtpGateBypass::isBypassed();
    }

    public function statusBadgeColor(): string
    {
        return match ($this->getRecord()->status) {
            'completed' => 'success',
            'in_progress' => 'info',
            'open' => 'warning',
            'cancelled' => 'gray',
            default => 'outline',
        };
    }

    public function goToStep(int $step): void
    {
        if ($step >= 1 && $step <= 3) {
            $this->wizardStep = $step;
        }
    }

    public function savePartyAction(): Action
    {
        return Action::make('saveParty')
            ->label('Save customer')
            ->action(function (): void {
                /** @var OutboundShippingSession $session */
                $session = $this->getRecord();

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

                $this->getRecord()->refresh()->loadMissing(['site', 'tradingPartner', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection']);
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
                /** @var OutboundShippingSession $session */
                $session = $this->getRecord();

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

                $this->getRecord()->refresh()->loadMissing(['site', 'tradingPartner', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection']);
                $this->hydrateWizardFromRecord();

                Notification::make()
                    ->title('References saved')
                    ->success()
                    ->send();
            });
    }

    public function declareSplitAction(): Action
    {
        return Action::make('declareSplit')
            ->label('Declare split / partial')
            ->icon(Heroicon::OutlinedArrowsPointingOut)
            ->color('warning')
            ->visible(fn (): bool => $this->isActive()
                && (int) $this->getRecord()->expected_count > 0
                && ! (bool) $this->getRecord()->split_declared)
            ->requiresConfirmation()
            ->modalHeading('Declare split / partial shipment?')
            ->modalDescription('Allows sending with fewer confirmed units than expected. Only confirmed EPCs are authored onto the shipping event; residual expected quantity stays on this ship order.')
            ->modalSubmitActionLabel('Declare split')
            ->action(function (): void {
                /** @var OutboundShippingSession $session */
                $session = $this->getRecord();

                try {
                    app(DeclareOutboundShippingSplit::class)->handle($session);
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Split blocked')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $this->getRecord()->refresh()->loadMissing(['site', 'tradingPartner', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection']);
                $this->hydrateWizardFromRecord();

                Notification::make()
                    ->title('Split declared')
                    ->body('You can send with the confirmed units only.')
                    ->success()
                    ->send();
            });
    }

    public function overrideQuantityGateAction(): Action
    {
        return Action::make('overrideQuantityGate')
            ->label('Override quantity gate')
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('danger')
            ->visible(function (): bool {
                /** @var OutboundShippingSession $session */
                $session = $this->getRecord();
                $user = auth()->user();

                if (! $user instanceof User || ! $user->can(Permissions::ShipQuantityGateOverride)) {
                    return false;
                }

                if (! $session->canSend() || (int) $session->expected_count > 0 || (bool) $session->quantity_gate_overridden) {
                    return false;
                }

                $session->loadMissing('outboundConnection');
                $connection = $session->outboundConnection;

                return $connection !== null && $connection->conformanceState()->requiresExpectedQuantity();
            })
            ->form([
                Textarea::make('reason')
                    ->label('Override reason')
                    ->required()
                    ->rows(3)
                    ->helperText('Audited justification for sending without an ASN/order expected unit count.'),
            ])
            ->modalHeading('Override quantity gate?')
            ->modalSubmitActionLabel('Override and allow send')
            ->action(function (array $data): void {
                /** @var OutboundShippingSession $session */
                $session = $this->getRecord();
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(OverrideOutboundShippingQuantityGate::class)->handle(
                        $session,
                        $user,
                        (string) ($data['reason'] ?? ''),
                    );
                } catch (AuthorizationException|DomainException|InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Override blocked')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $this->getRecord()->refresh()->loadMissing(['site', 'tradingPartner', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection']);
                $this->hydrateWizardFromRecord();

                Notification::make()
                    ->title('Quantity gate overridden')
                    ->body('You may send without an expected unit count. This override is audited.')
                    ->warning()
                    ->send();
            });
    }

    public function addFromReceivedAction(): Action
    {
        return Action::make('addFromReceived')
            ->label('Add from received')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->form([
                Select::make('receiving_session_id')
                    ->label('Receiving session')
                    ->options(fn (): array => ReceivingSessionResource::getEloquentQuery()
                        ->where('status', 'completed')
                        ->orderByDesc('completed_at')
                        ->limit(50)
                        ->pluck('id', 'id')
                        ->mapWithKeys(fn ($id): array => [(int) $id => 'Session #'.$id])
                        ->all())
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                /** @var OutboundShippingSession $session */
                $session = $this->getRecord();

                try {
                    $result = app(AddOutboundShippingEpcsFromReceivingSession::class)->handle(
                        $session,
                        (int) $data['receiving_session_id'],
                        userId: auth()->id(),
                    );
                } catch (DomainException $e) {
                    Notification::make()
                        ->title('Add blocked')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $this->getRecord()->refresh()->loadMissing(['site', 'tradingPartner', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection']);

                Notification::make()
                    ->title('Added from receiving')
                    ->body("Added {$result['added']} unit(s), skipped {$result['skipped']}.")
                    ->success()
                    ->send();

                $this->dispatch('outbound-shipping-scan-lines-updated')
                    ->to(ScanLinesRelationManager::class);
            });
    }

    public function updatedCustomerSearch(string $value): void
    {
        $this->customerSuggestions = app(SearchShipToCustomers::class)->handle($value);
        $this->customerDropdownOpen = $this->customerSuggestions !== [];
    }

    public function openCustomerDropdown(): void
    {
        // Focus/click shows the browse list; typing still filters via updatedCustomerSearch.
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

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
            $this->getInfolistContentComponent(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->declareSplitAction(),
            $this->overrideQuantityGateAction(),
            RegulatoryCompliance::apply(
                Action::make('sendShipment')
                    ->label('Send shipment')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('success')
                    ->visible(fn (): bool => $this->isActive() || $this->getRecord()->needsShippingEpcis())
                    ->disabled(fn (): bool => blank($this->asn_number)
                        || (blank($this->customer_po) && blank($this->invoice_number))
                        || ! $this->dscsa_affirm)
                    ->requiresConfirmation()
                    ->modalHeading('Send this shipment?')
                    ->modalDescription('Authors the shipping EPCIS document and schedules outbound transmission to the partner.')
                    ->modalSubmitActionLabel('Send shipment')
                    ->action(function (): void {
                        /** @var OutboundShippingSession $session */
                        $session = $this->getRecord();

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
                            $this->getRecord()->refresh()->loadMissing(['site', 'tradingPartner', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection']);
                            $this->hydrateWizardFromRecord();

                            Notification::make()
                                ->title('Send blocked')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $this->getRecord()->refresh()->loadMissing(['site', 'tradingPartner', 'shipToSite', 'outboundConnection', 'epcisDocument.outboundConnection']);
                        $this->hydrateWizardFromRecord();
                        $this->wizardStep = 3;

                        $copy = $this->shipCompleteCopy();
                        $portalUrl = OutboundPortalPickupNotice::signedUrl($this->getRecord());
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

                        $this->notifyOpenParentHierarchyIfNeeded($this->getRecord());

                        $this->dispatch('outbound-shipping-scan-lines-updated')
                            ->to(ScanLinesRelationManager::class);
                    }),
                'outbound_shipping_send',
                requireReason: false,
            ),
            Action::make('cancelShipOrder')
                ->label('Cancel')
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->canCancel())
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(CancelOutboundShippingSession::class)->handle($this->getRecord(), auth()->id());
                    } catch (DomainException $e) {
                        Notification::make()
                            ->title('Cancel blocked')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    $this->hydrateWizardFromRecord();

                    Notification::make()
                        ->title('Ship order cancelled')
                        ->success()
                        ->send();
                }),
            UnsubmittedSessionDeleteAction::forShipping(
                fn (OutboundShippingSession $record) => app(DeleteOutboundShippingSession::class)->handle($record, auth()->id()),
                OutboundShippingSessionResource::getUrl(name: 'index', panel: 'app'),
            ),
            Action::make('downloadShippingXml')
                ->label('Download EPCIS')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->epcisDocument !== null
                    && filled($this->getRecord()->epcisDocument?->payload_path))
                ->disabled(fn (): bool => ! ($this->getRecord()->epcisDocument instanceof EpcisDocument)
                    || ! EpcisDocumentXmlDownload::available($this->getRecord()->epcisDocument))
                ->tooltip(fn (): ?string => ($this->getRecord()->epcisDocument instanceof EpcisDocument
                    && EpcisDocumentXmlDownload::available($this->getRecord()->epcisDocument))
                    ? 'Download the shipping EPCIS XML'
                    : 'XML payload is missing from storage')
                ->action(function () {
                    $document = $this->getRecord()->epcisDocument;

                    if (! $document instanceof EpcisDocument || ! EpcisDocumentXmlDownload::available($document)) {
                        Notification::make()
                            ->title('XML file missing')
                            ->body('The shipping EPCIS path is recorded but the file is not on disk.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    /** @var User|null $actor */
                    $actor = auth()->user();

                    activity()
                        ->performedOn($document)
                        ->causedBy($actor)
                        ->withProperties([
                            'filename' => EpcisDocumentXmlDownload::filename($document),
                            'payload_path' => $document->payload_path,
                            'outbound_shipping_session_id' => $this->getRecord()->getKey(),
                        ])
                        ->log('Downloaded EPCIS XML');

                    return EpcisDocumentXmlDownload::response($document);
                }),
            $this->correctiveShipFromOrderAction(),
            Action::make('viewShippingDocument')
                ->label('View shipping EPCIS')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->epcisDocument !== null)
                ->url(fn (): ?string => $this->getRecord()->epcisDocument?->filamentViewUrl()),
        ];
    }

    /**
     * Open a fresh corrective order that points back at this order's shipping document,
     * so the amendment is traceable to what it corrects.
     */
    private function correctiveShipFromOrderAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('correctiveShipFromOrder')
                ->label('Corrective ship from this order')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('warning')
                // The correction authors EPCIS under this order's ship-from site, so the
                // operator needs access to that site even to open one.
                ->visible(fn (): bool => $this->isCompleted()
                    && $this->getRecord()->epcis_document_id !== null
                    && $this->canAccessShipFromSite())
                ->modalHeading('Start a corrective ship order')
                ->modalDescription('Scans on the new order are authorized by prior ship evidence instead of on-hand inventory at the ship-from site.')
                ->modalSubmitActionLabel('Open corrective order')
                ->schema([
                    Textarea::make('corrective_reason')
                        ->label('Reason for the correction')
                        ->required()
                        ->rows(3)
                        ->maxLength(2000)
                        ->helperText('Recorded on the new ship order and carried into the authored EPCIS notes.'),
                ])
                ->action(function (array $data): void {
                    /** @var OutboundShippingSession $session */
                    $session = $this->getRecord();

                    try {
                        $corrective = app(OpenOutboundShippingSession::class)->handle(
                            siteId: (int) $session->site_id,
                            openedBy: auth()->id(),
                            isCorrective: true,
                            correctiveReason: isset($data['corrective_reason']) ? (string) $data['corrective_reason'] : null,
                            correctsEpcisDocumentId: (int) $session->epcis_document_id,
                        );
                    } catch (AuthorizationException|InvalidArgumentException|DomainException $e) {
                        Notification::make()
                            ->title('Corrective order blocked')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    activity()
                        ->performedOn($corrective)
                        ->causedBy(auth()->user())
                        ->withProperties([
                            'site_id' => (int) $corrective->site_id,
                            'corrects_epcis_document_id' => (int) $corrective->corrects_epcis_document_id,
                            'corrective_reason' => $corrective->corrective_reason,
                        ])
                        ->log('Opened corrective ship order');

                    Notification::make()
                        ->title('Corrective ship order opened')
                        ->success()
                        ->send();

                    $this->redirect(OutboundShippingSessionResource::getUrl('view', ['record' => $corrective]));
                }),
            'outbound_shipping_corrective_open',
            requireReason: true,
            existingReasonField: 'corrective_reason',
        );
    }

    private function hydrateWizardFromRecord(): void
    {
        /** @var OutboundShippingSession $record */
        $record = $this->getRecord();

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
}
