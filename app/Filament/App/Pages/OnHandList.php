<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveProductFromIdentifier;
use App\Filament\Notifications\Notification;
use App\Models\Epcis\Epc;
use App\Models\Principal;
use App\Models\Product;
use App\Models\Quarantine\QuarantineHold;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\Ndc;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Shipping\OnHandExport;
use App\Support\Shipping\OnHandInvestigate;
use App\Support\Shipping\OnHandLotRollup;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use App\Support\Tracing\AssetTrackingUrl;
use App\Support\Tracing\Gs1DualDisplay;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class OnHandList extends Page implements HasKnowledgeBase, HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'On-hand';

    protected static ?string $title = 'On-hand';

    protected static ?int $navigationSort = 12;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.on-hand-list';

    public ?int $siteId = null;

    public ?int $principalId = null;

    public string $activeTab = 'lots';

    public string $scanInput = '';

    public string $investigatePaste = '';

    /** @var list<array<string, mixed>> */
    public array $investigateResults = [];

    public int $windowDays = 90;

    public ?string $filterGtin = null;

    public ?string $filterLot = null;

    /** When false, Serials lists outermost parents only (excludes open holds). */
    public bool $showContents = false;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'on-hand';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->hasAnyOperations()
            && JobRoleAccess::allowsAny(Permissions::NavReceive, Permissions::NavShip);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return TenantFeatures::forTenant(tenant())->showsWholesaleOperationsNav()
            && static::canAccess();
    }

    public function mount(): void
    {
        $this->siteId = CurrentSite::id()
            ?? array_key_first(EligibleReceiveSites::options($this->authUser()));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Last-seen custody at this site. Not a second inventory system. Asset Tracking is unchanged.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make()->visible(fn (): bool => $this->activeTab === 'serials'),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportLots')
                ->label('Export lots CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): ?StreamedResponse => $this->exportLotsCsv()),
            Action::make('exportSerials')
                ->label('Export serials CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): ?StreamedResponse => $this->exportSerialsCsv()),
            Action::make('exportAuditPack')
                ->label('Audit pack')
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->color('gray')
                ->action(fn (): ?StreamedResponse => $this->exportAuditPack()),
        ];
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['lots', 'serials', 'expiry', 'holds', 'investigate'], true)) {
            return;
        }

        $this->activeTab = $tab;
        if ($tab !== 'serials') {
            $this->resetTable();
        }
    }

    public function openLotSerials(string $gtin, string $lot): void
    {
        $this->filterGtin = $gtin;
        $this->filterLot = $lot;
        $this->showContents = true;
        $this->activeTab = 'serials';
        $this->resetTable();
    }

    public function clearSerialFilters(): void
    {
        $this->filterGtin = null;
        $this->filterLot = null;
        $this->showContents = false;
        $this->resetTable();
    }

    public function updatedShowContents(): void
    {
        $this->resetTable();
    }

    public function goToAssetTracking(): mixed
    {
        $scan = trim($this->scanInput);
        if ($scan === '') {
            Notification::make()->title('Enter or scan an identifier')->warning()->send();

            return null;
        }

        $url = AssetTrackingUrl::url($scan);
        if ($url === null) {
            Notification::make()->title('Could not build Asset Tracking link')->danger()->send();

            return null;
        }

        $this->scanInput = '';

        return redirect()->to($url);
    }

    public function runInvestigate(): void
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            $this->investigateResults = [];
            Notification::make()->title('Select a site first')->warning()->send();

            return;
        }

        $this->investigateResults = app(OnHandInvestigate::class)->classify($siteId, $this->investigatePaste);
    }

    public function canQuarantine(): bool
    {
        return JobRoleAccess::allows(Permissions::NavExceptions);
    }

    public function quarantineExpiryHit(int $epcId): void
    {
        $siteId = $this->resolvedSiteId();
        if ($epcId < 1 || $siteId === null || ! $this->canQuarantine()) {
            return;
        }

        $epc = Epc::query()->with('ilmd')->find($epcId);
        if (! $epc instanceof Epc || ! $this->expiryRows()->contains(fn (Epc $row): bool => (int) $row->getKey() === $epcId)) {
            Notification::make()->title('Not on this near-expiry list')->danger()->send();

            return;
        }

        $expiry = $epc->ilmd?->expiry_date?->toDateString() ?? 'unknown';
        $case = app(QuarantineService::class)->quarantineFromFindRecall(
            [$epcId],
            'On-hand near-expiry · expires '.$expiry,
            $this->authUser(),
        );
        $case->forceFill(['site_id' => $siteId])->save();

        Notification::make()->title('Quarantined')->success()->send();
    }

    /**
     * @return array<int, string>
     */
    public function siteOptions(): array
    {
        return EligibleReceiveSites::options($this->authUser());
    }

    public function supportsPrincipalFilter(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsPrincipals();
    }

    /**
     * @return array<int, string>
     */
    public function principalOptions(): array
    {
        return Principal::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /** @var LengthAwarePaginator<int, array<string, mixed>>|null */
    private ?LengthAwarePaginator $lotRowsCache = null;

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function lotRows(): LengthAwarePaginator
    {
        if ($this->lotRowsCache !== null) {
            return $this->lotRowsCache;
        }

        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            return $this->lotRowsCache = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25);
        }

        return $this->lotRowsCache = app(OnHandLotRollup::class)->paginate(
            $siteId,
            $this->resolvedPrincipalId(),
            $this->windowDays,
            25,
            'lotsPage',
        );
    }

    /** @var array<string, array{name: string, volume: ?string, ndc: ?string}> */
    private array $productDisplayCache = [];

    public function productLabel(array $row): string
    {
        return $this->productDisplay($row)['name'];
    }

    public function productVolume(array $row): ?string
    {
        return $this->productDisplay($row)['volume'];
    }

    public function productNdc(array $row): ?string
    {
        return $this->productDisplay($row)['ndc'];
    }

    /**
     * @return array{name: string, volume: ?string, ndc: ?string}
     */
    public function productDisplay(array $row): array
    {
        if (($row['gtin14'] ?? '') === OnHandLotRollup::SSCC_PRODUCT_KEY) {
            return ['name' => 'Containers (SSCC)', 'volume' => null, 'ndc' => null];
        }

        $gtin = (string) ($row['gtin14'] ?? '');
        if ($gtin === '') {
            return ['name' => 'Unknown product', 'volume' => null, 'ndc' => null];
        }

        if (isset($this->productDisplayCache[$gtin])) {
            return $this->productDisplayCache[$gtin];
        }

        $product = app(ResolveProductFromIdentifier::class)->handle($gtin);
        if (! $product instanceof Product) {
            return $this->productDisplayCache[$gtin] = [
                'name' => $gtin,
                'volume' => null,
                'ndc' => null,
            ];
        }

        $product->loadMissing('fdaProductPackaging');

        $name = filled($product->name) && strcasecmp((string) $product->name, 'N/A') !== 0
            ? (string) $product->name
            : $gtin;

        $packaging = $product->fdaProductPackaging;
        $volume = null;
        if (filled($packaging?->net_content_description)) {
            $volume = (string) $packaging->net_content_description;
        } elseif (filled($packaging?->description)) {
            $volume = (string) $packaging->description;
        } else {
            $volume = trim(implode(' ', array_filter([
                filled($product->strength) ? (string) $product->strength : null,
                filled($product->dosage_form) ? (string) $product->dosage_form : null,
            ])));
            $volume = $volume !== '' ? $volume : null;
        }

        $ndc = Ndc::formatPackageDisplay(
            $product->ndc ?? $product->ndc11,
            $product->package_ndc,
        );

        return $this->productDisplayCache[$gtin] = [
            'name' => $name,
            'volume' => $volume,
            'ndc' => $ndc,
        ];
    }

    /**
     * @return Collection<int, Epc>
     */
    public function expiryRows(): Collection
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            return collect();
        }

        $window = in_array($this->windowDays, [30, 60, 90], true) ? $this->windowDays : 90;
        $today = now()->toDateString();
        $until = now()->addDays($window)->toDateString();
        $principalId = $this->resolvedPrincipalId();

        $query = Epc::query()
            ->where('epcs.epc_type', 'sgtin')
            ->whereHas('ilmd', function ($query) use ($today, $until): void {
                $query->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '>=', $today)
                    ->whereDate('expiry_date', '<=', $until);
            })
            ->whereIn('epcs.id', app(ShippableEpcsAtSite::class)->query($siteId)->select('epcs.id'));

        if ($principalId !== null) {
            $query->where('epcs.principal_id', $principalId);
        }

        return $query
            ->with('ilmd')
            ->join('epc_ilmd', 'epc_ilmd.epc_id', '=', 'epcs.id')
            ->orderBy('epc_ilmd.expiry_date')
            ->orderBy('epcs.id')
            ->select('epcs.*')
            ->limit(200)
            ->get();
    }

    public function daysLeft(Epc $epc): ?int
    {
        $date = $epc->ilmd?->expiry_date;
        if ($date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($date->startOfDay(), false);
    }

    /**
     * @return Collection<int, QuarantineHold>
     */
    public function holdRows(): Collection
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            return collect();
        }

        $onHand = app(OnHandExport::class)->serialsQuery($siteId, $this->resolvedPrincipalId())->select('epcs.id');

        return QuarantineHold::query()
            ->open()
            ->whereIn('epc_id', $onHand)
            ->with(['epc.ilmd'])
            ->orderByDesc('opened_at')
            ->limit(200)
            ->get();
    }

    public function identifier(Epc $epc): string
    {
        return Gs1DualDisplay::forEpc($epc)['primary'];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->serialsTableQuery())
            ->columns([
                TextColumn::make('identifier')
                    ->label('Identifier')
                    ->state(fn (Epc $record): string => Gs1DualDisplay::forEpc($record)['primary'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.$search.'%';

                        return $query->where(function (Builder $inner) use ($like, $search): void {
                            $inner->where('epcs.epc_uri', 'like', $like)
                                ->orWhere('epcs.sscc18', 'like', $like)
                                ->orWhere('epcs.serial_number', 'like', $like)
                                ->orWhere('epcs.ai_01_21', 'like', $like)
                                ->orWhere('epcs.gtin14', 'like', $like)
                                ->orWhere('epcs.epc_uri', $search)
                                ->orWhere('epcs.sscc18', $search)
                                ->orWhere('epcs.ai_01_21', $search);
                        });
                    })
                    ->fontFamily(FontFamily::Mono)
                    ->url(fn (Epc $record): ?string => AssetTrackingUrl::forEpc($record))
                    ->wrap(),
                TextColumn::make('epc_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('gtin14')
                    ->label('GTIN')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('ilmd.lot_number')
                    ->label('Lot')
                    ->placeholder('—'),
                TextColumn::make('ilmd.expiry_date')
                    ->label('Expiry')
                    ->date()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('epc_type')
                    ->label('Type')
                    ->options([
                        'sscc' => 'SSCC',
                        'sgtin' => 'SGTIN',
                    ]),
            ])
            ->recordActions([
                Action::make('trace')
                    ->label('Trace')
                    ->icon(Heroicon::OutlinedMapPin)
                    ->url(fn (Epc $record): ?string => AssetTrackingUrl::forEpc($record)),
            ])
            ->paginated([10, 25, 50])
            ->extremePaginationLinks()
            ->emptyStateHeading('No on-hand serials')
            ->emptyStateDescription('Receive or commission inventory at this site to populate custody.');
    }

    /** @return Builder<Epc> */
    private function serialsTableQuery(): Builder
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null || $this->activeTab !== 'serials') {
            return Epc::query()->whereRaw('0 = 1');
        }

        $lotDrillDown = $this->filterGtin !== null;
        $showAll = $this->showContents || $lotDrillDown;

        return app(OnHandExport::class)
            ->serialsQuery(
                $siteId,
                $this->resolvedPrincipalId(),
                $this->filterGtin,
                $this->filterLot,
                parentsOnly: ! $showAll,
                excludeOpenHolds: ! $showAll,
            )
            ->with('ilmd');
    }

    private function exportLotsCsv(): ?StreamedResponse
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            Notification::make()->title('Select a site first')->warning()->send();

            return null;
        }

        return app(OnHandExport::class)->streamLotsCsv(
            $siteId,
            $this->resolvedPrincipalId(),
            $this->siteOptions()[$siteId] ?? 'site-'.$siteId,
        );
    }

    private function exportSerialsCsv(): ?StreamedResponse
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            Notification::make()->title('Select a site first')->warning()->send();

            return null;
        }

        return app(OnHandExport::class)->streamSerialsCsv(
            $siteId,
            $this->resolvedPrincipalId(),
            $this->siteOptions()[$siteId] ?? 'site-'.$siteId,
            $this->filterGtin,
            $this->filterLot,
        );
    }

    private function exportAuditPack(): ?StreamedResponse
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            Notification::make()->title('Select a site first')->warning()->send();

            return null;
        }

        return app(OnHandExport::class)->streamAuditPack(
            $siteId,
            $this->resolvedPrincipalId(),
            $this->siteOptions()[$siteId] ?? 'site-'.$siteId,
        );
    }

    private function resolvedSiteId(): ?int
    {
        if ($this->siteId === null) {
            return null;
        }

        $user = $this->authUser();
        if ($user !== null && ! SiteAccess::canAccessSite($user, $this->siteId)) {
            return null;
        }

        return $this->siteId;
    }

    private function resolvedPrincipalId(): ?int
    {
        if (! $this->supportsPrincipalFilter()) {
            return null;
        }

        if ($this->principalId === null || $this->principalId <= 0) {
            return null;
        }

        return $this->principalId;
    }

    private function authUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function getDocumentation(): array|string
    {
        return 'operations.on-hand-and-unpacked';
    }
}
