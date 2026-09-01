<?php

namespace App\Filament\App\Resources\TradingPartners\RelationManagers;

use App\Actions\MasterData\AddFdaPackagesToTradingPartner;
use App\Actions\MasterData\UpdatePartnerProductAssortment;
use App\Enums\AuthorizationStatus;
use App\Enums\PartnerType;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\TradingPartner;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaTenantLink;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use App\Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Products';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('partner_item_number')
                ->label('Partner item number')
                ->maxLength(64)
                ->helperText('The partner\'s own SKU for this product. Must be unique within this partner; leave blank when unknown.')
                ->rule(fn (?Product $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    /** @var TradingPartner $partner */
                    $partner = $this->getOwnerRecord();

                    $conflictId = UpdatePartnerProductAssortment::conflictingProductId(
                        $partner,
                        $value,
                        $record?->getKey() !== null ? (int) $record->getKey() : null,
                    );

                    if ($conflictId !== null) {
                        $fail(UpdatePartnerProductAssortment::conflictMessage(
                            UpdatePartnerProductAssortment::normalizeItemNumber($value) ?? '',
                            $conflictId,
                        ));
                    }
                }),
            TextInput::make('uom_code')
                ->label('UOM code')
                ->maxLength(8),
            TextInput::make('units_per_case')
                ->label('Units per case')
                ->numeric()
                ->minValue(1),
            Toggle::make('is_primary')
                ->label('Primary receive-from')
                ->helperText('Default supplier for this product when ordering.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->rx()->with(['fdaProduct.packaging', 'tradingPartner']))
            ->columns([
                TextColumn::make('gtin')
                    ->label('GTIN')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ndc')
                    ->label('NDC')
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('tradingPartner.name')
                    ->label('Manufacturer')
                    ->toggleable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('dosage_form')
                    ->label('Dosage')
                    ->toggleable(),
                TextColumn::make('strength')
                    ->toggleable(),
                TextColumn::make('pivot.partner_item_number')
                    ->label('Partner SKU')
                    ->fontFamily(FontFamily::Mono)
                    ->toggleable(),
                TextColumn::make('pivot.authorization_status')
                    ->label('Authorization')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => AuthorizationStatus::tryFrom($state)?->operatorLabel() ?? 'Incomplete')
                    ->color(fn (?string $state): string => AuthorizationStatus::tryFrom($state)?->badgeColor() ?? 'gray'),
                TextColumn::make('net_contents')
                    ->label('Net contents')
                    ->wrap()
                    ->state(function (Product $record): string {
                        if (filled($record->package_ndc)) {
                            $description = $record->fdaProduct?->packaging
                                ->firstWhere('package_ndc', $record->package_ndc)
                                ?->description
                                ?? FdaProductPackaging::query()->where('package_ndc', $record->package_ndc)->value('description');

                            return filled($description) ? $description : '—';
                        }

                        $all = $record->fdaProduct?->packaging
                            ->pluck('description')
                            ->filter()
                            ->unique()
                            ->values()
                            ->implode('; ');

                        return filled($all) ? $all : '—';
                    }),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('GTIN, NDC, or name')
            ->filters([
                TernaryFilter::make('is_active')->default(true),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->emptyStateHeading('No products for this partner')
            ->emptyStateDescription(fn (): string => $this->receiveActionDisabled()
                ? 'Activate this partner and link its catalog before adding products.'
                : 'Receive catalog products to build this partner\'s assortment.')
            ->emptyStateActions([
                $this->receiveProductsAction()
                    ->visible(fn (): bool => ! $this->receiveActionDisabled()),
            ])
            ->headerActions([
                $this->receiveProductsAction(),
            ])
            ->recordActions(RecordActionGroup::make([
                RegulatoryCompliance::apply(
                    EditAction::make()
                        ->slideOver()
                        ->modalHeading('Edit assortment')
                        ->using(function (Product $record, array $data): Product {
                            /** @var TradingPartner $partner */
                            $partner = $this->getOwnerRecord();

                            try {
                                app(UpdatePartnerProductAssortment::class)->handle($partner, $record, $data);
                            } catch (DomainException $e) {
                                Notification::make()
                                    ->title('Update blocked')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();

                                throw new Halt;
                            }

                            return $record;
                        }),
                    'trading_partner_products_edit',
                    requireReason: false,
                ),
                RegulatoryCompliance::apply(
                    // Filament leaves detach to `isReadOnly()`, so the policy is named here.
                    DetachAction::make()
                        ->authorize('detach')
                        ->label('Remove')
                        ->modalHeading('Remove product from partner')
                        ->modalDescription('Removes this product from this partner’s receivable set. The product record is kept if used elsewhere.'),
                    'trading_partner_products_detach',
                    requireReason: true,
                ),
            ]));
    }

    private function receiveProductsAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('receiveProducts')
                ->label('New product')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->authorize('create')
                ->modalHeading('Receive products from partner')
                ->modalDescription(fn (): string => $this->receiveModalDescription())
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalSubmitActionLabel('Add products')
                ->disabled(fn (): bool => $this->receiveActionDisabled())
                ->tooltip(fn (): ?string => $this->receiveActionTooltip())
                ->form([
                    Select::make('packaging_ids')
                        ->label('Products to receive')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->searchDebounce(500)
                        ->required()
                        ->native(false)
                        ->helperText(fn (): ?string => $this->receiveCatalogHelperText())
                        ->getSearchResultsUsing(fn (?string $search): array => $this->searchFdaPackages($search))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->fdaPackageOptionLabels($values)),
                    Toggle::make('auto_add_manufacturer')
                        ->label('Add manufacturer from FDA when missing')
                        ->helperText('Creates the FDA labeler as an authorized manufacturer partner and links product identity.')
                        ->default(true)
                        ->visible(fn (): bool => $this->showAutoAddManufacturerToggle()),
                ])
                ->action(function (array $data): void {
                    /** @var TradingPartner $partner */
                    $partner = $this->getOwnerRecord();

                    $result = app(AddFdaPackagesToTradingPartner::class)->handle(
                        $partner,
                        $data['packaging_ids'] ?? [],
                        autoAddManufacturer: (bool) ($data['auto_add_manufacturer'] ?? false),
                    );

                    $added = $result['added'];
                    $attached = $result['attached'];
                    $skipped = $result['skipped'];
                    $manufacturerPending = $result['manufacturer_pending'];
                    $manufacturerAdded = $result['manufacturer_added'];

                    if ($added === 0 && $attached === 0 && $skipped === 0) {
                        Notification::make()
                            ->title('No products added')
                            ->warning()
                            ->send();

                        return;
                    }

                    $parts = [];
                    if ($added > 0) {
                        $parts[] = $added === 1 ? '1 product created' : "{$added} products created";
                    }
                    if ($attached > 0) {
                        $parts[] = $attached === 1 ? '1 existing product linked' : "{$attached} existing products linked";
                    }
                    if ($skipped > 0) {
                        $parts[] = $skipped === 1 ? '1 already linked' : "{$skipped} already linked";
                    }
                    if ($manufacturerAdded > 0) {
                        $parts[] = $manufacturerAdded === 1
                            ? '1 manufacturer added from FDA'
                            : "{$manufacturerAdded} manufacturers added from FDA";
                    }

                    $body = implode('. ', $parts).'.';

                    $notification = Notification::make()
                        ->title('Products updated')
                        ->body($body);

                    if ($manufacturerPending > 0) {
                        $pendingLabel = $manufacturerPending === 1
                            ? '1 product is pending manufacturer authorization'
                            : "{$manufacturerPending} products are pending manufacturer authorization";

                        $notification
                            ->warning()
                            ->body($body.' '.$pendingLabel.'.')
                            ->send();

                        return;
                    }

                    $notification->success()->send();
                }),
            'trading_partner_products_receive',
            requireReason: false,
        );
    }

    private function receiveModalDescription(): string
    {
        /** @var TradingPartner $partner */
        $partner = $this->getOwnerRecord();

        if ($this->isManufacturerScoped($partner)) {
            return 'Select FDA packages from this manufacturer to add to your receivable set.';
        }

        if ($this->isWholesalerLikePartner($partner)) {
            return 'Select Rx FDA packages to receive from this partner. Adds them to your receivable list for this partner — it does not confirm they carry each item. Manufacturer identity stays on the product.';
        }

        return 'Select any Rx FDA packages to receive from this partner. Manufacturer identity stays on the product; this partner is the supplier.';
    }

    private function receiveCatalogHelperText(): ?string
    {
        /** @var TradingPartner $partner */
        $partner = $this->getOwnerRecord();

        if ($this->isWholesalerLikePartner($partner)) {
            return 'Adds selected products to your receivable list for this partner. It does not confirm they carry them in their catalog.';
        }

        return null;
    }

    private function showAutoAddManufacturerToggle(): bool
    {
        /** @var TradingPartner $partner */
        $partner = $this->getOwnerRecord();

        return $this->isWholesalerLikePartner($partner);
    }

    private function isWholesalerLikePartner(TradingPartner $partner): bool
    {
        return in_array($partner->partner_type, [
            PartnerType::Wholesaler,
            PartnerType::Logistics3pl,
            PartnerType::Other,
        ], true);
    }

    private function receiveActionDisabled(): bool
    {
        /** @var TradingPartner $partner */
        $partner = $this->getOwnerRecord();

        if (! $partner->is_active) {
            return true;
        }

        return $this->isManufacturerScoped($partner)
            && FdaTenantLink::organizationId($partner) === null;
    }

    private function receiveActionTooltip(): ?string
    {
        /** @var TradingPartner $partner */
        $partner = $this->getOwnerRecord();

        if (! $partner->is_active) {
            return 'Activate this partner before adding products';
        }

        return $this->receiveActionDisabled()
            ? 'Link this manufacturer to an FDA organization first'
            : null;
    }

    private function isManufacturerScoped(TradingPartner $partner): bool
    {
        return app(AddFdaPackagesToTradingPartner::class)->requiresLabelerScope($partner);
    }

    /**
     * @return array<int|string, string>
     */
    private function searchFdaPackages(?string $search): array
    {
        /** @var TradingPartner $partner */
        $partner = $this->getOwnerRecord();

        if (blank($search)) {
            return [];
        }

        $organizationId = FdaTenantLink::organizationId($partner);

        if ($this->isManufacturerScoped($partner) && $organizationId === null) {
            return [];
        }

        $alreadyLinkedPackagingIds = $partner->products()
            ->whereNotNull('fda_product_packaging_id')
            ->pluck('fda_product_packaging_id')
            ->all();

        $term = '%'.$search.'%';

        $query = FdaProductPackaging::query()
            ->where('is_active', true)
            ->whereNotIn('id', $alreadyLinkedPackagingIds)
            ->whereHas('product', function (Builder $product) use ($organizationId, $partner): void {
                $product->prescription();

                if ($this->isManufacturerScoped($partner) && $organizationId !== null) {
                    $product->where('fda_organization_id', $organizationId);
                }
            })
            ->where(function (Builder $builder) use ($term): void {
                $builder->where('package_ndc', 'like', $term)
                    ->orWhere('ndc11', 'like', $term)
                    ->orWhere('gtin', 'like', $term)
                    ->orWhereHas('product', function (Builder $product) use ($term): void {
                        $product->where('name', 'like', $term)
                            ->orWhere('brand_name', 'like', $term)
                            ->orWhere('generic_name', 'like', $term)
                            ->orWhere('product_ndc', 'like', $term);
                    });
            })
            ->with(['product.fdaOrganization'])
            ->orderBy('package_ndc')
            ->limit(50);

        return $query->get()
            ->mapWithKeys(fn (FdaProductPackaging $packaging): array => [
                $packaging->getKey() => $this->formatFdaPackageOption($packaging),
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int|string, string>
     */
    private function fdaPackageOptionLabels(array $values): array
    {
        return FdaProductPackaging::query()
            ->with(['product.fdaOrganization'])
            ->whereIn('id', $values)
            ->get()
            ->mapWithKeys(fn (FdaProductPackaging $packaging): array => [
                $packaging->getKey() => $this->formatFdaPackageOption($packaging),
            ])
            ->all();
    }

    private function formatFdaPackageOption(FdaProductPackaging $packaging): string
    {
        $listing = $packaging->product;
        $name = DisplayName::clean($listing?->name ?: $listing?->brand_name ?: $listing?->generic_name) ?: 'Package';
        $ndc = $packaging->package_ndc ?: $listing?->product_ndc;
        $manufacturer = DisplayName::clean($listing?->fdaOrganization?->name);

        $label = filled($ndc) ? "{$name} — {$ndc}" : $name;

        if (filled($manufacturer)) {
            $label .= " ({$manufacturer})";
        }

        return $label;
    }
}
