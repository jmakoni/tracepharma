<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Filament\App\Pages\AssetTracking\Schemas\AssetTrackingInfolist;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\SerializationLots\SerializationLotResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisEvent;
use App\Models\L3\SerializationLotContainerField;
use App\Models\User;
use App\Services\Tracing\BuildAssetTrace;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Receiving\ResolveOpenReceiveUrl;
use App\Support\TenantFeatures;
use App\Support\Tracing\CbvStatusColor;
use App\Support\Tracing\EpcContextLinks;
use App\Support\Tracing\Gs1DualDisplay;
use App\Support\Tracing\LocationDisplayResolver;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

class AssetTracking extends Page implements HasKnowledgeBase, HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Asset Tracking';

    protected static ?string $title = 'Asset Tracking';

    protected static ?int $navigationSort = 4;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.asset-tracking';

    public string $scan = '';

    /**
     * Optional UTC instant for point-in-time custody (ISO-8601 or datetime-local).
     */
    public ?string $asOf = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $trace = null;

    public string $resultsTab = 'tracking';

    /**
     * Per-request memoization for {@see self::containerField()} — not Livewire-tracked,
     * recomputed each render; reset on every new trace.
     */
    private ?SerializationLotContainerField $containerField = null;

    private bool $containerFieldResolved = false;

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        return ($features->supportsInboundIntegrations() || $features->hasAnyOperations())
            && JobRoleAccess::allowsAny(
                Permissions::NavIntegrations,
                Permissions::NavReceive,
                Permissions::NavShip,
                Permissions::NavExceptions,
                Permissions::NavVerify,
            );
    }

    public function mount(): void
    {
        $scan = request()->query('scan');
        $asOf = request()->query('as_of');

        if (filled($asOf)) {
            $this->asOf = (string) $asOf;
        }

        if (filled($scan)) {
            $this->scan = (string) $scan;
            $this->runTrace(app(BuildAssetTrace::class));
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Scan a unit or pallet to see status and custody history. Optionally set As of (UTC) for a point-in-time snapshot.';
    }

    public function runTrace(BuildAssetTrace $builder): void
    {
        $scan = ElementString::normalize(trim($this->scan));
        $this->scan = $scan;

        if ($scan === '') {
            Notification::make()
                ->title('Scan required')
                ->body('Scan an SGTIN or SSCC to trace.')
                ->warning()
                ->send();

            $this->dispatch('focus-scan');

            return;
        }

        $resolved = app(ResolveEpcFromScan::class)->handle($scan);
        $epc = $resolved['epc'];
        if ($epc instanceof Epc && ! $this->canAccessEpc($epc)) {
            $this->trace = null;
            $this->containerField = null;
            $this->containerFieldResolved = false;
            $this->cacheSchema('content', null);
            $this->cachedHeaderActions = [];
            $this->resetTable();

            Notification::make()
                ->title('Not authorized')
                ->body('You do not have access to this asset at its last-seen site.')
                ->danger()
                ->send();

            $this->dispatch('scan-result', tone: 'error');
            $this->dispatch('focus-scan');

            return;
        }

        $asOfCarbon = $this->parseAsOfUtc();
        $this->trace = $builder->handle($scan, $asOfCarbon);
        $this->resultsTab = 'tracking';
        $this->containerField = null;
        $this->containerFieldResolved = false;

        // Filament caches the `content` schema for the request. If it was built
        // before this action (discoveredSchemaNames rebuild) or needs new
        // components after a successful trace, flush so results render immediately.
        $this->cacheSchema('content', null);
        $this->cachedHeaderActions = [];
        $this->resetTable();

        if ($this->trace['found']) {
            Notification::make()
                ->title('Asset found')
                ->body((string) ($this->trace['primary_identifier'] ?? $scan))
                ->success()
                ->send();

            $this->dispatch('scan-result', tone: $this->trace['status_tone']);
        } else {
            Notification::make()
                ->title('No asset found')
                ->body('No trace record for this scan.')
                ->warning()
                ->send();

            $this->dispatch('scan-result', tone: 'error');
        }

        $this->dispatch('focus-scan');
    }

    private function parseAsOfUtc(): ?Carbon
    {
        $raw = trim((string) ($this->asOf ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, 'UTC')->utc();
        } catch (\Throwable) {
            Notification::make()
                ->title('Invalid as-of time')
                ->body('Use a valid UTC datetime (e.g. 2026-08-28 15:00:00).')
                ->warning()
                ->send();

            return null;
        }
    }

    private function canAccessEpc(Epc $epc): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $gln = app(ResolveEpcLastKnownGln::class)->forEpc((int) $epc->getKey());
        $siteId = SiteAccess::organizationSiteIdForGln($gln);

        if ($siteId !== null) {
            return SiteAccess::canAccessShipToSite($user, $siteId);
        }

        // Unmapped last-seen site: only SitesAccessAll (Owners) — never fail-open to
        // any user who merely has ≥1 site assignment.
        return SiteAccess::canAccessShipToSite($user, null);
    }

    public function setResultsTab(string $tab): void
    {
        if (! in_array($tab, ['tracking', 'epcis', 'children', 'transactions'], true)) {
            return;
        }

        $this->resultsTab = $tab;
        $this->resetTable();
    }

    public function updatedResultsTab(?string $value): void
    {
        if (! in_array((string) $value, ['tracking', 'epcis', 'children', 'transactions'], true)) {
            $this->resultsTab = 'tracking';

            return;
        }

        $this->resetTable();
    }

    public function findRecallUrl(): ?string
    {
        if (! EpcisDocumentResource::canAccess()) {
            return null;
        }

        $url = EpcisDocumentResource::getUrl('index');

        return $url.(str_contains($url, '?') ? '&' : '?').'action=findRecall';
    }

    public function verifyProductUrl(): ?string
    {
        if (! VerifyProduct::canAccess()) {
            return null;
        }

        $params = $this->trace['verify_url_params'] ?? null;

        if ($params !== null) {
            return VerifyProduct::getUrl($params);
        }

        $scan = trim($this->scan) !== '' ? trim($this->scan) : (string) ($this->trace['scan'] ?? '');

        return VerifyProduct::getUrl(filled($scan) ? ['barcode' => $scan] : []);
    }

    /**
     * Guardian L3 per-container fields for the currently traced EPC, keyed by
     * indexed `epc_uri` — never selects the sibling `fields` JSON column on
     * list pages, only here on a single-EPC lookup.
     */
    public function containerField(): ?SerializationLotContainerField
    {
        if ($this->containerFieldResolved) {
            return $this->containerField;
        }

        $this->containerFieldResolved = true;

        if ($this->trace === null || ! ($this->trace['found'] ?? false)) {
            return $this->containerField = null;
        }

        $epcUri = (string) ($this->trace['urn'] ?? '');
        if ($epcUri === '') {
            return $this->containerField = null;
        }

        return $this->containerField = SerializationLotContainerField::query()
            ->where('epc_uri', $epcUri)
            ->with('lot')
            ->first();
    }

    public function containerFieldLotUrl(): ?string
    {
        $lot = $this->containerField()?->lot;

        if ($lot === null || ! SerializationLotResource::canAccess()) {
            return null;
        }

        return SerializationLotResource::getUrl('view', ['record' => $lot], panel: 'app');
    }

    public function openReceiveBarcode(): string
    {
        $scan = trim($this->scan);

        if ($scan !== '') {
            return $scan;
        }

        return trim((string) ($this->trace['scan'] ?? ''));
    }

    /**
     * @return list<array{key: string, label: string, url: ?string, opens: bool}>
     */
    public function contextLinks(): array
    {
        if ($this->trace === null || ! ($this->trace['found'] ?? false)) {
            return [];
        }

        $barcode = $this->openReceiveBarcode();
        $epc = $this->currentEpc();

        return app(EpcContextLinks::class)->forEpc(
            $epc,
            filled($barcode) ? $barcode : null,
            auth()->id(),
        );
    }

    public function content(Schema $schema): Schema
    {
        $hasResults = fn (): bool => (bool) ($this->trace['found'] ?? false);

        return $schema
            ->columns(1)
            ->components([
                View::make('filament.app.partials.asset-tracking-scan'),
                Group::make(AssetTrackingInfolist::components($this))
                    ->visible($hasResults),
                Section::make()
                    ->compact()
                    ->visible($hasResults)
                    ->schema([
                        Tabs::make('Activity')
                            ->livewireProperty('resultsTab')
                            ->columnSpanFull()
                            ->tabs([
                                'tracking' => Tab::make('Tracking')
                                    ->schema([
                                        View::make('filament.app.asset-tracking.timeline-and-map'),
                                    ]),
                                'epcis' => Tab::make('EPCIS')
                                    ->schema([
                                        EmbeddedTable::make(),
                                    ]),
                                'children' => Tab::make('Children')
                                    ->badge(fn (): ?string => (($count = (int) ($this->trace['children_count'] ?? 0)) > 0)
                                        ? (string) $count
                                        : null)
                                    ->schema([
                                        EmbeddedTable::make(),
                                    ]),
                                'transactions' => Tab::make('Transactions')
                                    ->schema([
                                        EmbeddedTable::make(),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        $epc = $this->currentEpc();

        if ($epc === null) {
            return $table
                ->query(EpcisEvent::query()->whereRaw('1 = 0'))
                ->columns([
                    TextColumn::make('event_type')->label('Event type'),
                ])
                ->emptyStateHeading('Scan an asset to see events');
        }

        return match ($this->resultsTab) {
            'children' => $this->childrenTable($table, $epc),
            'transactions' => $this->transactionsTable($table, $epc),
            default => $this->eventsTable($table, $epc),
        };
    }

    protected function getHeaderActions(): array
    {
        // Register all actions with visibility closures so they refresh after runTrace
        // without requiring a full header-action cache rebuild.
        return [
            Action::make('open_receive')
                ->label('Open receive')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('success')
                ->visible(fn (): bool => $this->hasContextLink('open_receive'))
                ->action(function (): void {
                    if (! ReceivingSessionResource::canAccess()) {
                        return;
                    }

                    $barcode = $this->openReceiveBarcode();
                    $url = app(ResolveOpenReceiveUrl::class)->handle($barcode, auth()->id());

                    if ($url === null) {
                        $hasContext = $this->hasContextLink('open_receive');

                        Notification::make()
                            ->title('No receive session')
                            ->body($hasContext
                                ? 'Could not open receive — check site access or session status.'
                                : 'No open receive context for this barcode.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $this->redirect($url);
                }),
            Action::make('open_transfer')
                ->label('Open transfer')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->visible(fn (): bool => $this->hasContextLink('open_transfer'))
                ->url(fn (): ?string => $this->contextLinkUrl('open_transfer')),
            Action::make('open_ship')
                ->label('Open ship')
                ->icon(Heroicon::OutlinedTruck)
                ->color('gray')
                ->visible(fn (): bool => $this->hasContextLink('open_ship'))
                ->url(fn (): ?string => $this->contextLinkUrl('open_ship')),
            Action::make('verify_product')
                ->label('Verify product')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('primary')
                ->visible(fn (): bool => $this->hasContextLink('verify_product'))
                ->url(fn (): ?string => $this->contextLinkUrl('verify_product')),
        ];
    }

    private function hasContextLink(string $key): bool
    {
        return collect($this->contextLinks())->contains(fn (array $link): bool => ($link['key'] ?? null) === $key);
    }

    private function contextLinkUrl(string $key): ?string
    {
        $link = collect($this->contextLinks())->first(fn (array $row): bool => ($row['key'] ?? null) === $key);

        $url = $link['url'] ?? null;

        return filled($url) ? (string) $url : null;
    }

    private function currentEpc(): ?Epc
    {
        if ($this->trace === null || ! ($this->trace['found'] ?? false)) {
            return null;
        }

        $epc = $this->trace['epc'] ?? null;

        if ($epc instanceof Epc) {
            return $epc;
        }

        if (is_numeric($epc)) {
            return Epc::query()->find((int) $epc);
        }

        if (is_array($epc) && isset($epc['id'])) {
            return Epc::query()->find((int) $epc['id']);
        }

        $scan = (string) ($this->trace['scan'] ?? '');

        if ($scan === '') {
            return null;
        }

        return app(ResolveEpcFromScan::class)->handle($scan)['epc'];
    }

    private function eventsTable(Table $table, Epc $epc): Table
    {
        $builder = app(BuildAssetTrace::class);
        $payload = $builder->eventsForTrackingTable($epc, $this->parseAsOfUtc());
        $limit = $builder->trackingTableLimit();

        return $table
            ->records(fn (): Collection => $payload['records']->values())
            ->description($payload['truncated']
                ? "Showing the {$limit} most recent events (older history truncated)."
                : null)
            ->columns([
                TextColumn::make('event_time')
                    ->label('Event time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('biz_step')
                    ->label('Business step')
                    ->badge()
                    ->state(fn (EpcisEvent $record): ?string => $this->stripCbvPrefix($record->biz_step))
                    ->color(fn (EpcisEvent $record): string => CbvStatusColor::businessStep($record->biz_step))
                    ->placeholder('—'),
                TextColumn::make('site')
                    ->label('Site')
                    ->state(fn (EpcisEvent $record): ?string => $this->eventSiteLabel($record))
                    ->placeholder('—'),
                TextColumn::make('disposition')
                    ->label('Disposition')
                    ->badge()
                    ->state(fn (EpcisEvent $record): ?string => $this->stripCbvPrefix($record->disposition))
                    ->color(fn (EpcisEvent $record): string => CbvStatusColor::disposition($record->disposition))
                    ->placeholder('—'),
                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->color(fn (EpcisEvent $record): string => CbvStatusColor::action($record->action)),
                TextColumn::make('event_type')
                    ->label('Event type')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('event_id')
                    ->label('Event ID')
                    ->fontFamily(FontFamily::Mono)
                    ->limit(24)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('read_point_gln')
                    ->label('Read point')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_trackable')
                    ->label('Trackable')
                    ->boolean()
                    ->state(fn (EpcisEvent $record): bool => BuildAssetTrace::isTrackable($record))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('event_time')
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('No events for this asset');
    }

    private function childrenTable(Table $table, Epc $epc): Table
    {
        $builder = app(BuildAssetTrace::class);

        return $table
            ->query($builder->childrenQuery($epc, $this->parseAsOfUtc()))
            ->columns([
                TextColumn::make('identifier')
                    ->label('Identifier')
                    ->state(function (Epc $record): ?string {
                        if ($record->epc_type === 'sscc') {
                            return filled($record->ai_00)
                                ? (string) $record->ai_00
                                : (filled($record->sscc18) ? (string) $record->sscc18 : null);
                        }

                        $label = EpcBarcodeDisplay::forEpc($record);

                        return filled($label) ? $label : null;
                    })
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('alt_identifier')
                    ->label('Alt identifier (GS1)')
                    ->state(fn (Epc $record): string => Gs1DualDisplay::forEpc($record)['gs1_barcode'])
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
                TextColumn::make('epc_uri')
                    ->label('URN')
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('trace')
                    ->label('Trace')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->action(function (Epc $record): void {
                        $this->scan = (string) $record->epc_uri;
                        $this->runTrace(app(BuildAssetTrace::class));
                    }),
            ])
            ->emptyStateHeading('No contained units');
    }

    private function transactionsTable(Table $table, Epc $epc): Table
    {
        $builder = app(BuildAssetTrace::class);
        $payload = $builder->transactionsForEpc($epc);
        $limit = $builder->trackingTableLimit();

        return $table
            ->records(fn (): Collection => $payload['records']->values())
            ->description($payload['truncated']
                ? "Biz transactions from the {$limit} most recent events (older history truncated)."
                : null)
            ->columns([
                TextColumn::make('name')
                    ->label('Name'),
                TextColumn::make('urn')
                    ->label('Type URN')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable(),
                TextColumn::make('value')
                    ->label('Value')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->limit(48)
                    ->tooltip(fn (?string $state): ?string => $state),
            ])
            ->paginated(false)
            ->emptyStateHeading('No biz transactions recorded');
    }

    private function eventSiteLabel(EpcisEvent $event): ?string
    {
        $gln = $event->biz_location_gln ?: $event->read_point_gln;
        $location = $event->locations->firstWhere('location_type', 'bizLocation') ?? $event->locations->first();

        if (blank($gln) && $location === null) {
            return null;
        }

        return app(LocationDisplayResolver::class)->resolve($gln, $location)['label'] ?? null;
    }

    private function stripCbvPrefix(?string $uri): ?string
    {
        if (! filled($uri)) {
            return null;
        }

        if (! str_contains($uri, ':')) {
            return $uri;
        }

        $segment = (string) str($uri)->afterLast(':');

        return $segment !== '' ? $segment : $uri;
    }

    public static function getDocumentation(): array|string
    {
        return 'workflows.asset-tracking';
    }
}
