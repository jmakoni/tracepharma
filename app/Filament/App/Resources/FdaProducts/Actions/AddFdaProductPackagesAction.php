<?php

namespace App\Filament\App\Resources\FdaProducts\Actions;

use App\Actions\MasterData\AddFdaPackagesToTradingPartner;
use App\Actions\MasterData\EnsureOrganizationPartnerFromFda;
use App\Enums\PartnerType;
use App\Filament\App\Resources\TradingPartners\TradingPartnerResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\TradingPartner;
use App\Support\Catalog\DisplayName;
use App\Support\MasterData\MajorWholesalers;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AddFdaProductPackagesAction
{
    private const MISSING_MANUFACTURER_OPTION = '__manufacturer_not_set_up__';

    public static function make(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('addProduct')
                ->label('Add product')
                ->icon(Heroicon::OutlinedPlus)
                ->color('primary')
                ->modalHeading('Add product packages')
                ->modalDescription(fn (?FdaProduct $record): string => self::modalDescription($record))
                ->modalWidth(Width::ThreeExtraLarge)
                ->modalSubmitActionLabel('Add products')
                ->modalSubmitAction(fn (Action $action): Action|false => self::canShowProductForm() ? $action : false)
                ->extraModalFooterActions(fn (?FdaProduct $record): array => self::hasActiveTradingPartners()
                    ? []
                    : [
                        Action::make('goToTradingPartners')
                            ->label('Create trading partner')
                            ->url(TradingPartnerResource::getUrl('index'))
                            ->color('primary'),
                    ])
                ->disabled(fn (?FdaProduct $record): bool => $record !== null
                    && self::eligiblePackagingQuery($record)->doesntExist())
                ->tooltip(function (?FdaProduct $record): ?string {
                    if ($record !== null && self::eligiblePackagingQuery($record)->doesntExist()) {
                        return 'No active FDA packages exist for this product';
                    }

                    return null;
                })
                ->form(function (?FdaProduct $record): array {
                    if (! self::canShowProductForm()) {
                        return [
                            Placeholder::make('empty_partners')
                                ->label('Add a trading partner first')
                                ->content('Authorize products only after you have at least one active manufacturer or wholesaler.'),
                        ];
                    }

                    $components = [];

                    if ($record === null) {
                        $components[] = Select::make('fda_product_id')
                            ->label('FDA product')
                            ->placeholder('Search by NDC, brand, or generic name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->searchDebounce(500)
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $set('packaging_ids', []);

                                $fdaProduct = self::resolveFdaProduct(null, $state);
                                $set(
                                    'trading_partner_id',
                                    $fdaProduct === null ? null : self::preferredManufacturerPartnerId($fdaProduct),
                                );
                            })
                            ->getSearchResultsUsing(fn (?string $search): array => self::searchPrescriptionFdaProducts($search))
                            ->getOptionLabelUsing(fn ($value): ?string => self::fdaProductOptionLabel($value));
                    }

                    $components[] = Placeholder::make('missing_manufacturer_warning')
                        ->label('Manufacturer not authorized')
                        ->content(function (Get $get) use ($record): string {
                            $fdaProduct = self::resolveFdaProduct($record, $get('fda_product_id'));
                            $name = self::missingManufacturerName($fdaProduct);

                            return "Manufacturer {$name} is not in your Authorized Partners.";
                        })
                        ->visible(function (Get $get) use ($record): bool {
                            $fdaProduct = self::resolveFdaProduct($record, $get('fda_product_id'));

                            return self::isManufacturerMissing($fdaProduct);
                        });

                    $components[] = Select::make('trading_partner_id')
                        ->label('Receive from partner')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->live()
                        ->disabled(fn (Get $get): bool => self::resolveFdaProduct($record, $get('fda_product_id')) === null)
                        ->helperText(function (Get $get) use ($record): ?string {
                            $fdaProduct = self::resolveFdaProduct($record, $get('fda_product_id'));

                            if ($fdaProduct === null) {
                                return 'Manufacturer first, then distributors / wholesalers';
                            }

                            $partnerId = $get('trading_partner_id');

                            if (filled($partnerId) && self::isReceiveFromDistributor($partnerId)) {
                                return 'Adds this product to your receivable list for this partner. It does not confirm they carry it in their catalog.';
                            }

                            return 'Manufacturer first, then distributors / wholesalers';
                        })
                        ->options(function (Get $get) use ($record): array {
                            $fdaProduct = self::resolveFdaProduct($record, $get('fda_product_id'));

                            return self::receiveFromPartnerOptions($fdaProduct);
                        })
                        ->getOptionLabelUsing(function (mixed $value): ?string {
                            if ((string) $value === self::MISSING_MANUFACTURER_OPTION) {
                                return null;
                            }

                            if (MajorWholesalers::isSentinel($value)) {
                                $orgId = MajorWholesalers::catalogIdFromSentinel($value);
                                $name = $orgId === null
                                    ? null
                                    : DisplayName::clean(
                                        FdaOrganization::query()->whereKey($orgId)->value('name'),
                                    );

                                return filled($name) ? "{$name} (Wholesaler — not set up)" : null;
                            }

                            $partner = TradingPartner::query()->find($value);

                            if ($partner === null) {
                                return null;
                            }

                            $name = DisplayName::clean($partner->name) ?: 'Partner';
                            $type = $partner->partner_type?->label() ?? 'Partner';

                            return "{$name} ({$type})";
                        })
                        ->disableOptionWhen(fn (mixed $value): bool => (string) $value === self::MISSING_MANUFACTURER_OPTION)
                        ->default(function () use ($record): ?int {
                            if ($record === null) {
                                return null;
                            }

                            return self::preferredManufacturerPartnerId($record);
                        });

                    $components[] = SchemaActions::make([
                        Action::make('addManufacturerFromCatalog')
                            ->label('Add manufacturer')
                            ->color('gray')
                            ->action(function (Get $get, Set $set) use ($record): void {
                                $fdaProduct = self::resolveFdaProduct($record, $get('fda_product_id'));
                                $organization = $fdaProduct?->fdaOrganization;
                                if ($organization === null) {
                                    return;
                                }
                                $partner = app(EnsureOrganizationPartnerFromFda::class)
                                    ->handle($organization, PartnerType::Manufacturer);

                                if ($partner === null) {
                                    Notification::make()
                                        ->title('That labeler is your own organization')
                                        ->body('You cannot add your own company as a trading partner.')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $set('trading_partner_id', $partner->getKey());
                                $set('auto_add_manufacturer', false);
                                Notification::make()
                                    ->title('Manufacturer added')
                                    ->body('You can now authorize receive-from for this partner.')
                                    ->success()
                                    ->send();
                            }),
                    ])
                        ->visible(fn (Get $get): bool => self::isManufacturerMissing(self::resolveFdaProduct($record, $get('fda_product_id'))));

                    $components[] = SchemaActions::make([
                        Action::make('addWholesalerFromCatalog')
                            ->label('Add wholesaler')
                            ->color('gray')
                            ->action(function (Get $get, Set $set): void {
                                $orgId = MajorWholesalers::catalogIdFromSentinel($get('trading_partner_id'));
                                $organization = $orgId === null
                                    ? null
                                    : FdaOrganization::query()->find($orgId);
                                if ($organization === null) {
                                    return;
                                }
                                $partner = app(EnsureOrganizationPartnerFromFda::class)
                                    ->handle($organization, PartnerType::Wholesaler);

                                if ($partner === null) {
                                    Notification::make()
                                        ->title('That wholesaler is your own organization')
                                        ->body('You cannot add your own company as a trading partner.')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $set('trading_partner_id', $partner->getKey());
                                Notification::make()
                                    ->title('Wholesaler added')
                                    ->body('You can now authorize receive-from for this partner.')
                                    ->success()
                                    ->send();
                            }),
                    ])
                        ->visible(fn (Get $get): bool => MajorWholesalers::isSentinel($get('trading_partner_id')));

                    $components[] = Toggle::make('auto_add_manufacturer')
                        ->label('Add manufacturer from catalog and authorize')
                        ->default(true)
                        ->visible(function (Get $get) use ($record): bool {
                            $fdaProduct = self::resolveFdaProduct($record, $get('fda_product_id'));

                            if (! self::isManufacturerMissing($fdaProduct)) {
                                return false;
                            }

                            $partnerId = $get('trading_partner_id');

                            if (blank($partnerId)) {
                                return true;
                            }

                            return self::isReceiveFromDistributor($partnerId);
                        });

                    $components[] = Select::make('packaging_ids')
                        ->label('Product packages')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->searchDebounce(500)
                        ->required()
                        ->native(false)
                        ->disabled(fn (Get $get): bool => self::resolveFdaProduct($record, $get('fda_product_id')) === null)
                        ->options(function (Get $get) use ($record): array {
                            $fdaProduct = self::resolveFdaProduct($record, $get('fda_product_id'));

                            if ($fdaProduct === null) {
                                return [];
                            }

                            return self::searchEligiblePackages($fdaProduct, null);
                        })
                        ->getSearchResultsUsing(function (?string $search, Get $get) use ($record): array {
                            $fdaProduct = self::resolveFdaProduct($record, $get('fda_product_id'));

                            if ($fdaProduct === null) {
                                return [];
                            }

                            return self::searchEligiblePackages($fdaProduct, $search);
                        })
                        ->getOptionLabelsUsing(fn (array $values): array => self::packagingOptionLabels($values));

                    return $components;
                })
                ->action(function (?FdaProduct $record, array $data): void {
                    if (! self::canShowProductForm()) {
                        return;
                    }

                    $fdaProduct = self::resolveFdaProduct($record, $data['fda_product_id'] ?? null);

                    if ($fdaProduct === null) {
                        Notification::make()
                            ->title('No products added')
                            ->warning()
                            ->send();

                        return;
                    }

                    $partnerId = $data['trading_partner_id'] ?? null;

                    if (MajorWholesalers::isSentinel($partnerId)) {
                        $orgId = MajorWholesalers::catalogIdFromSentinel($partnerId);
                        $organization = $orgId === null
                            ? null
                            : FdaOrganization::query()->find($orgId);
                        $partner = $organization === null
                            ? null
                            : app(EnsureOrganizationPartnerFromFda::class)
                                ->handle($organization, PartnerType::Wholesaler);
                    } else {
                        $partner = TradingPartner::query()->find($partnerId);
                    }

                    if ($partner === null) {
                        Notification::make()
                            ->title('No products added')
                            ->warning()
                            ->send();

                        return;
                    }

                    $result = app(AddFdaPackagesToTradingPartner::class)->handle(
                        $partner,
                        $data['packaging_ids'] ?? [],
                        autoAddManufacturer: (bool) ($data['auto_add_manufacturer'] ?? false),
                    );

                    self::sendProductsUpdatedNotification($result, $partner);
                }),
            'fda_add_product',
            requireReason: false,
        );
    }

    private static function modalDescription(?FdaProduct $record): string
    {
        if (! self::canShowProductForm()) {
            return 'Add at least one active trading partner before selecting FDA packages.';
        }

        if (! self::hasActiveTradingPartners()) {
            return 'Select an Rx FDA product and packages. Major wholesalers can be added from the receive-from list.';
        }

        if ($record === null) {
            return 'Select an Rx FDA product, then choose packages and the partner you receive them from.';
        }

        return 'Select Rx FDA packages for this NDC and the partner you receive them from.';
    }

    private static function canShowProductForm(): bool
    {
        return self::hasActiveTradingPartners() || MajorWholesalers::fdaOrganizations()->isNotEmpty();
    }

    private static function hasActiveTradingPartners(): bool
    {
        if (! tenancy()->initialized) {
            return true;
        }

        return TradingPartner::query()->where('is_active', true)->exists();
    }

    private static function isReceiveFromDistributor(mixed $partnerId): bool
    {
        if (MajorWholesalers::isSentinel($partnerId)) {
            return true;
        }

        if (! is_numeric($partnerId)) {
            return false;
        }

        $partner = TradingPartner::query()->find((int) $partnerId);

        return $partner !== null && self::isWholesalerLikePartnerType($partner->partner_type);
    }

    private static function isWholesalerLikePartnerType(?PartnerType $type): bool
    {
        return in_array($type, [
            PartnerType::Wholesaler,
            PartnerType::Logistics3pl,
            PartnerType::Other,
        ], true);
    }

    private static function isManufacturerMissing(?FdaProduct $fdaProduct): bool
    {
        if ($fdaProduct === null || $fdaProduct->fda_organization_id === null) {
            return false;
        }

        return ! TradingPartner::query()
            ->where('is_active', true)
            ->where('fda_organization_id', $fdaProduct->fda_organization_id)
            ->exists();
    }

    private static function missingManufacturerName(?FdaProduct $fdaProduct): string
    {
        if ($fdaProduct === null || $fdaProduct->fda_organization_id === null) {
            return 'Unknown';
        }

        $fdaProduct->loadMissing('fdaOrganization');

        $name = DisplayName::clean($fdaProduct->fdaOrganization?->name);

        return filled($name) ? $name : 'Unknown';
    }

    /**
     * @param  array{added: int, attached: int, skipped: int, manufacturer_pending: int, manufacturer_added: int}  $result
     */
    private static function sendProductsUpdatedNotification(array $result, TradingPartner $partner): void
    {
        $added = $result['added'];
        $attached = $result['attached'];
        $skipped = $result['skipped'];
        $manufacturerPending = $result['manufacturer_pending'];
        $manufacturerAdded = $result['manufacturer_added'];

        if ($added === 0 && $attached === 0 && $skipped === 0) {
            $notification = Notification::make()
                ->title('No products added')
                ->warning();

            if (app(AddFdaPackagesToTradingPartner::class)->requiresLabelerScope($partner)) {
                $notification->body('No matching FDA packages for this manufacturer labeler. Link the partner to an FDA organization or choose a different receive-from partner.');
            }

            $notification->send();

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
    }

    private static function resolveFdaProduct(?FdaProduct $record, mixed $fdaProductId): ?FdaProduct
    {
        if ($record !== null) {
            return $record;
        }

        if (blank($fdaProductId)) {
            return null;
        }

        return FdaProduct::query()->find($fdaProductId);
    }

    /**
     * Manufacturer for this FDA labeler first, then wholesalers / 3PLs / others.
     *
     * @return array<int|string, string>
     */
    private static function receiveFromPartnerOptions(?FdaProduct $fdaProduct): array
    {
        if ($fdaProduct === null) {
            return [];
        }

        $partners = TradingPartner::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'partner_type', 'fda_organization_id']);

        $organizationId = $fdaProduct->fda_organization_id;

        $labelerManufacturer = $organizationId === null
            ? null
            : $partners->first(
                fn (TradingPartner $partner): bool => (int) $partner->fda_organization_id === (int) $organizationId
            );

        $distributors = $partners
            ->filter(function (TradingPartner $partner) use ($labelerManufacturer): bool {
                if ($labelerManufacturer !== null && $partner->is($labelerManufacturer)) {
                    return false;
                }

                return self::isWholesalerLikePartnerType($partner->partner_type);
            })
            ->sortBy(function (TradingPartner $partner): string {
                $rank = match ($partner->partner_type) {
                    PartnerType::Wholesaler => '1',
                    PartnerType::Logistics3pl => '2',
                    PartnerType::Other => '3',
                    default => '9',
                };

                return $rank.(DisplayName::clean($partner->name) ?? '');
            })
            ->values();

        $distributorOptions = $distributors
            ->mapWithKeys(function (TradingPartner $partner): array {
                $name = DisplayName::clean($partner->name) ?: 'Partner';
                $type = $partner->partner_type?->label() ?? 'Partner';

                return [$partner->getKey() => "{$name} ({$type})"];
            })
            ->all();

        $manufacturerOptions = [];

        if ($labelerManufacturer !== null) {
            $name = DisplayName::clean($labelerManufacturer->name) ?: 'Partner';
            $manufacturerOptions[$labelerManufacturer->getKey()] = "{$name} (Manufacturer)";
        }

        $majorSentinelOptions = self::majorWholesalerSentinelOptions($partners);

        if ($organizationId !== null && $labelerManufacturer === null) {
            $name = self::missingManufacturerName($fdaProduct);

            return [
                self::MISSING_MANUFACTURER_OPTION => "{$name} (Manufacturer — not set up)",
            ] + $majorSentinelOptions + $distributorOptions;
        }

        return $manufacturerOptions + $majorSentinelOptions + $distributorOptions;
    }

    /**
     * @param  Collection<int, TradingPartner>  $activePartners
     * @return array<string, string>
     */
    private static function majorWholesalerSentinelOptions(Collection $activePartners): array
    {
        if (MajorWholesalers::hasAnyAuthorizedMajor()) {
            return [];
        }

        $linkedMajorOrgIds = $activePartners
            ->pluck('fda_organization_id')
            ->filter()
            ->all();

        $options = [];

        foreach (MajorWholesalers::fdaOrganizations() as $organization) {
            if (in_array($organization->getKey(), $linkedMajorOrgIds, true)) {
                continue;
            }

            $name = DisplayName::clean($organization->name) ?: 'Wholesaler';
            $options[MajorWholesalers::sentinel($organization->getKey())] = "{$name} (Wholesaler — not set up)";
        }

        return $options;
    }

    private static function preferredManufacturerPartnerId(FdaProduct $fdaProduct): ?int
    {
        if ($fdaProduct->fda_organization_id === null) {
            return null;
        }

        $id = TradingPartner::query()
            ->where('is_active', true)
            ->where('fda_organization_id', $fdaProduct->fda_organization_id)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return array<int|string, string>
     */
    private static function searchPrescriptionFdaProducts(?string $search): array
    {
        if (blank($search)) {
            return [];
        }

        return FdaProduct::query()
            ->prescription()
            ->where(function (Builder $builder) use ($search): void {
                $builder->where('product_ndc', 'like', '%'.$search.'%')
                    ->orWhere('brand_name', 'like', '%'.$search.'%')
                    ->orWhere('generic_name', 'like', '%'.$search.'%');
            })
            ->orderBy('product_ndc')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (FdaProduct $product): array => [
                $product->getKey() => self::formatFdaProductOption($product),
            ])
            ->all();
    }

    private static function fdaProductOptionLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $product = FdaProduct::query()->find($value);

        return $product ? self::formatFdaProductOption($product) : null;
    }

    private static function formatFdaProductOption(FdaProduct $product): string
    {
        $ndc = $product->product_ndc;
        $brand = DisplayName::clean($product->brand_name);
        $generic = DisplayName::clean($product->generic_name);
        $name = filled($brand) ? $brand : (filled($generic) ? $generic : 'Product');

        $label = $name;

        if (filled($ndc)) {
            $label .= ' — '.$ndc;
        }

        if (filled($brand) && filled($generic)) {
            $label .= ' ('.$generic.')';
        }

        return $label;
    }

    /**
     * @return Builder<FdaProductPackaging>
     */
    private static function eligiblePackagingQuery(FdaProduct $fdaProduct): Builder
    {
        return FdaProductPackaging::query()
            ->where('is_active', true)
            ->where('fda_product_id', $fdaProduct->getKey());
    }

    /**
     * @return array<int|string, string>
     */
    private static function searchEligiblePackages(FdaProduct $fdaProduct, ?string $search): array
    {
        $query = self::eligiblePackagingQuery($fdaProduct)->with('product');

        if (filled($search)) {
            $term = '%'.$search.'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('package_ndc', 'like', $term)
                    ->orWhere('ndc11', 'like', $term)
                    ->orWhere('gtin', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        return $query
            ->orderBy('package_ndc')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (FdaProductPackaging $packaging): array => [
                $packaging->getKey() => self::formatPackagingOption($packaging),
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int|string, string>
     */
    private static function packagingOptionLabels(array $values): array
    {
        return FdaProductPackaging::query()
            ->with('product')
            ->whereIn('id', $values)
            ->get()
            ->mapWithKeys(fn (FdaProductPackaging $packaging): array => [
                $packaging->getKey() => self::formatPackagingOption($packaging),
            ])
            ->all();
    }

    private static function formatPackagingOption(FdaProductPackaging $packaging): string
    {
        $listing = $packaging->product;
        $name = DisplayName::clean($listing?->name ?: $listing?->brand_name ?: $listing?->generic_name) ?: 'Package';
        $packageNdc = $packaging->package_ndc;
        $gtin = $packaging->gtin;

        $label = $name;

        if (filled($packageNdc)) {
            $label .= ' — '.$packageNdc;
        }

        if (filled($gtin)) {
            $label .= ' · '.$gtin;
        }

        if (filled($packaging->description)) {
            $label .= ' ('.$packaging->description.')';
        }

        $alreadyOnTenant = Product::query()
            ->where(function (Builder $query) use ($packaging): void {
                $query->where('fda_product_packaging_id', $packaging->getKey());

                if (filled($packaging->gtin)) {
                    $query->orWhere('gtin', $packaging->gtin);
                }

                if (filled($packaging->package_ndc)) {
                    $query->orWhere('package_ndc', $packaging->package_ndc);
                }

                if (filled($packaging->ndc11)) {
                    $query->orWhere('ndc11', $packaging->ndc11);
                }
            })
            ->exists();

        if ($alreadyOnTenant) {
            $label .= ' · in directory';
        }

        return $label;
    }
}
