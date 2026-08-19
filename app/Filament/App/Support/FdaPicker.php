<?php

namespace App\Filament\App\Support;

use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\Site;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaPrefill;
use BackedEnum;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared "pick from FDA registry" control for tenant create/edit forms.
 */
final class FdaPicker
{
    private const SEARCH_LIMIT = 50;

    /** @var list<string> */
    private const ORGANIZATION_OPTION_COLUMNS = [
        'id',
        'name',
        'original_name',
        'canonical_name',
        'street_address',
        'street_address_2',
        'city',
        'state_province',
        'postal_code',
        'country_code',
        'full_address',
    ];

    /** @var list<string> */
    private const ESTABLISHMENT_OPTION_COLUMNS = [
        'id',
        'fda_organization_id',
        'name',
        'firm_name',
        'street_address',
        'street_address_2',
        'city',
        'state_province',
        'postal_code',
        'country_code',
        'full_address',
    ];

    /** @var list<string> */
    private const FACILITY_OPTION_COLUMNS = [
        'id',
        'fda_organization_id',
        'name',
        'facility_name',
        'street_address',
        'street_address_2',
        'city',
        'state_province',
        'postal_code',
        'country_code',
        'full_address',
    ];

    /**
     * @param  class-string<Model>  $model
     * @param  callable(Model): array<string, mixed>  $prefill
     * @param  callable(Builder, Get): Builder|null  $constrain
     * @param  callable(Builder, string): array<int|string, string>|null  $searchUsing
     * @param  callable(Model): string|null  $optionLabel
     * @param  callable(Builder): array<int|string, string>|null  $optionsUsing  Used when search is blank and preload is allowed
     * @param  callable(Get): bool|null  $preloadWhenBlank  Blank search returns options only when this returns true. When null, blank search is type-first (empty).
     * @param  callable(Select): Select|null  $configureSelect
     * @return array<int, Hidden|Select>
     */
    public static function make(
        string $model,
        string $idField,
        callable $prefill,
        string $label,
        ?callable $constrain = null,
        ?callable $searchUsing = null,
        ?callable $optionLabel = null,
        ?callable $optionsUsing = null,
        ?callable $preloadWhenBlank = null,
        ?callable $configureSelect = null,
        ?callable $afterPick = null,
    ): array {
        $select = Select::make('_fda_pick_'.$idField)
            ->label($label)
            ->placeholder('Start from the FDA registry…')
            ->searchable()
            ->searchDebounce(500)
            ->getSearchResultsUsing(function (?string $search, Get $get) use (
                $model,
                $constrain,
                $searchUsing,
                $optionsUsing,
                $preloadWhenBlank,
            ): array {
                $builder = $model::query();
                if ($constrain) {
                    $builder = $constrain($builder, $get);
                }

                if (blank($search)) {
                    // National catalogs stay type-first; scoped/small lists opt in via preloadWhenBlank.
                    if ($preloadWhenBlank === null || ! $preloadWhenBlank($get)) {
                        return [];
                    }

                    if ($optionsUsing) {
                        return $optionsUsing($builder);
                    }

                    return $builder
                        ->orderBy('name')
                        ->limit(self::SEARCH_LIMIT)
                        ->pluck('name', 'id')
                        ->all();
                }

                if ($searchUsing) {
                    return $searchUsing($builder, $search);
                }

                return $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orderBy('name')
                    ->limit(self::SEARCH_LIMIT)
                    ->pluck('name', 'id')
                    ->all();
            })
            ->getOptionLabelUsing(function ($value) use ($model, $optionLabel): ?string {
                if (blank($value)) {
                    return null;
                }

                $record = $model::query()->find($value);

                if ($record === null) {
                    return null;
                }

                return $optionLabel ? $optionLabel($record) : $record->name;
            })
            ->dehydrated(false)
            ->live(debounce: 500)
            ->afterStateUpdated(function (?string $state, Set $set) use ($model, $prefill, $afterPick, $idField): void {
                if (blank($state)) {
                    $set($idField, null);

                    if ($afterPick) {
                        $afterPick(null, $set);
                    }

                    return;
                }

                $record = $model::query()->find($state);
                if (! $record) {
                    return;
                }

                foreach ($prefill($record) as $key => $value) {
                    $value = $value instanceof BackedEnum ? $value->value : $value;
                    if (FdaPrefill::isBlankIdentityValue($key, $value)) {
                        continue;
                    }

                    $set($key, $value);
                }

                if ($afterPick) {
                    $afterPick($record, $set);
                }
            });

        if ($configureSelect) {
            $select = $configureSelect($select);
        }

        return [
            $select,
            Hidden::make($idField),
        ];
    }

    /** @return array<int, Hidden|Select> */
    public static function organization(): array
    {
        return self::make(
            FdaOrganization::class,
            'fda_organization_id',
            FdaPrefill::organizationAttributes(...),
            'From FDA organization',
            constrain: fn (Builder $q, Get $get): Builder => $q->where('is_active', true),
            searchUsing: fn (Builder $builder, string $search): array => self::formatOrganizationOptions(
                self::filterOrganizationsByTerm($builder, $search)->get(self::ORGANIZATION_OPTION_COLUMNS),
            ),
            optionLabel: fn (FdaOrganization $org): string => self::formatOrganizationOption($org),
            configureSelect: fn (Select $select): Select => $select
                ->allowHtml()
                ->helperText('Type to search FDA organizations by name, GLN, DUNS, or address.')
                ->placeholder('Type to search FDA organizations…'),
        );
    }

    /**
     * Options for the FDA organization select (typeahead; blank search is empty for the national list).
     *
     * @return array<int|string, string>
     */
    public static function organizationOptions(?string $search): array
    {
        $builder = FdaOrganization::query()->where('is_active', true);

        if (blank($search)) {
            return [];
        }

        return self::formatOrganizationOptions(
            self::filterOrganizationsByTerm($builder, $search)
                ->get(self::ORGANIZATION_OPTION_COLUMNS),
        );
    }

    /**
     * Trading-partner picker: search orgs, establishments, and WDD facilities.
     * Picking a site still prefills the parent organization.
     *
     * @return array<int, Hidden|Select>
     */
    public static function tradingPartnerOrganization(): array
    {
        $select = Select::make('_fda_pick_fda_organization_id')
            ->label('From FDA organization')
            ->placeholder('Type to search organizations, establishments, or facilities…')
            ->helperText('Type a company, site, city, street, or ZIP. National FDA lists are too large to browse on open.')
            ->searchable()
            ->searchDebounce(500)
            ->allowHtml()
            ->native(false)
            ->dehydrated(false)
            ->live(debounce: 500)
            ->getSearchResultsUsing(
                fn (?string $search): array => self::tradingPartnerOrganizationOptions($search),
            )
            ->getOptionLabelUsing(
                fn (mixed $value): ?string => self::tradingPartnerOrganizationOptionLabel(
                    is_string($value) ? $value : null,
                ),
            )
            ->afterStateUpdated(function (?string $state, Set $set): void {
                $set('fda_pick', $state);

                if (blank($state)) {
                    $set('fda_organization_id', null);

                    return;
                }

                $organization = self::resolveTradingPartnerOrganization($state);
                if ($organization === null) {
                    return;
                }

                foreach (FdaPrefill::organizationAttributes($organization) as $key => $value) {
                    $value = $value instanceof BackedEnum ? $value->value : $value;
                    if (FdaPrefill::isBlankIdentityValue($key, $value)) {
                        continue;
                    }

                    $set($key, $value);
                }
            });

        return [
            $select,
            Hidden::make('fda_organization_id'),
            Hidden::make('fda_pick'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function tradingPartnerOrganizationOptions(?string $search): array
    {
        if (blank($search)) {
            return [];
        }

        $options = [];

        foreach ([
            self::formatTradingPartnerOrganizationOptions(
                self::filterOrganizationsByTerm(
                    FdaOrganization::query()->where('is_active', true),
                    $search,
                )->get(self::ORGANIZATION_OPTION_COLUMNS),
            ),
            self::formatTradingPartnerEstablishmentOptions(
                self::filterEstablishmentsByTerm(
                    FdaEstablishment::query()
                        ->where('is_active', true)
                        ->whereNotNull('fda_organization_id'),
                    $search,
                )->get(self::ESTABLISHMENT_OPTION_COLUMNS),
            ),
            self::formatTradingPartnerFacilityOptions(
                self::filterTradingPartnerFacilitiesByTerm(
                    FdaWddFacility::query()
                        ->where('is_active', true)
                        ->whereNotNull('fda_organization_id'),
                    $search,
                )->get(self::FACILITY_OPTION_COLUMNS),
            ),
        ] as $chunk) {
            foreach ($chunk as $key => $label) {
                $options[$key] = $label;

                if (count($options) >= self::SEARCH_LIMIT) {
                    return $options;
                }
            }
        }

        return $options;
    }

    public static function resolveTradingPartnerOrganization(?string $pick): ?FdaOrganization
    {
        $parsed = self::parseTradingPartnerPick($pick);

        if ($parsed === null) {
            return null;
        }

        return match ($parsed['type']) {
            'org' => FdaOrganization::query()->find($parsed['id']),
            'est' => FdaEstablishment::query()->find($parsed['id'])?->organization,
            'wdd' => FdaWddFacility::query()->find($parsed['id'])?->organization,
        };
    }

    /**
     * Partner Sites tab: establishments and WDD facilities for one FDA organization.
     *
     * @return array<int, Hidden|Select>
     */
    public static function partnerLocation(?int $organizationId): array
    {
        $select = Select::make('_fda_pick_partner_location')
            ->label('FDA location')
            ->placeholder('Pick an establishment or WDD facility…')
            ->helperText('Only FDA plants and warehouses that belong to this partner.')
            ->searchable()
            ->searchDebounce(500)
            ->allowHtml()
            ->native(false)
            ->dehydrated(false)
            ->live(debounce: 500)
            ->getSearchResultsUsing(
                fn (?string $search): array => self::partnerLocationOptions($search, $organizationId),
            )
            ->getOptionLabelUsing(
                fn (mixed $value): ?string => self::tradingPartnerOrganizationOptionLabel(
                    is_string($value) ? $value : null,
                ),
            )
            ->afterStateUpdated(function (?string $state, Set $set): void {
                $set('fda_pick', $state);

                if (blank($state)) {
                    $set('fda_establishment_id', null);
                    $set('fda_wdd_facility_id', null);

                    return;
                }

                $parsed = self::parseTradingPartnerPick($state);

                if ($parsed === null || $parsed['type'] === 'org') {
                    return;
                }

                if ($parsed['type'] === 'est') {
                    $record = FdaEstablishment::query()->find($parsed['id']);

                    if ($record === null) {
                        return;
                    }

                    foreach (FdaPrefill::establishmentAttributes($record) as $key => $value) {
                        $value = $value instanceof BackedEnum ? $value->value : $value;
                        if (FdaPrefill::isBlankIdentityValue($key, $value)) {
                            continue;
                        }

                        $set($key, $value);
                    }

                    return;
                }

                $record = FdaWddFacility::query()->find($parsed['id']);

                if ($record === null) {
                    return;
                }

                foreach (FdaPrefill::wddFacilityAttributes($record) as $key => $value) {
                    $value = $value instanceof BackedEnum ? $value->value : $value;
                    if (FdaPrefill::isBlankIdentityValue($key, $value)) {
                        continue;
                    }

                    $set($key, $value);
                }

                $set('fda_establishment_id', null);
            });

        return [
            $select,
            Hidden::make('fda_pick'),
            Hidden::make('fda_establishment_id'),
            Hidden::make('fda_wdd_facility_id'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function partnerLocationOptions(?string $search, ?int $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        $excluded = self::excludedPartnerLocationKeys();

        $estQuery = FdaEstablishment::query()
            ->where('is_active', true)
            ->where('fda_organization_id', $organizationId);
        $wddQuery = FdaWddFacility::query()
            ->where('is_active', true)
            ->where('fda_organization_id', $organizationId);

        if ($excluded['est'] !== []) {
            $estQuery->whereNotIn('id', $excluded['est']);
        }

        if ($excluded['wdd'] !== []) {
            $wddQuery->whereNotIn('id', $excluded['wdd']);
        }

        if ($excluded['gln'] !== []) {
            $estQuery->where(function (Builder $query) use ($excluded): void {
                $query->whereNull('gln')->orWhereNotIn('gln', $excluded['gln']);
            });
            $wddQuery->where(function (Builder $query) use ($excluded): void {
                $query->whereNull('gln')->orWhereNotIn('gln', $excluded['gln']);
            });
        }

        if (blank($search)) {
            return self::mergePartnerLocationOptions(
                self::formatTradingPartnerEstablishmentOptions(
                    $estQuery
                        ->orderBy('name')
                        ->limit(self::SEARCH_LIMIT)
                        ->get(self::ESTABLISHMENT_OPTION_COLUMNS),
                ),
                self::formatTradingPartnerFacilityOptions(
                    $wddQuery
                        ->orderBy('name')
                        ->limit(self::SEARCH_LIMIT)
                        ->get(self::FACILITY_OPTION_COLUMNS),
                ),
            );
        }

        return self::mergePartnerLocationOptions(
            self::formatTradingPartnerEstablishmentOptions(
                self::filterEstablishmentsByTerm($estQuery, $search)
                    ->get(self::ESTABLISHMENT_OPTION_COLUMNS),
            ),
            self::formatTradingPartnerFacilityOptions(
                self::filterTradingPartnerFacilitiesByTerm($wddQuery, $search)
                    ->get(self::FACILITY_OPTION_COLUMNS),
            ),
        );
    }

    /**
     * @param  array<string, string>  $establishments
     * @param  array<string, string>  $facilities
     * @return array<string, string>
     */
    private static function mergePartnerLocationOptions(array $establishments, array $facilities): array
    {
        $options = [];

        foreach ([$establishments, $facilities] as $chunk) {
            foreach ($chunk as $key => $label) {
                $options[$key] = $label;

                if (count($options) >= self::SEARCH_LIMIT) {
                    return $options;
                }
            }
        }

        return $options;
    }

    /**
     * @return array{est: list<int>, wdd: list<int>, gln: list<string>}
     */
    private static function excludedPartnerLocationKeys(): array
    {
        $excluded = [
            'est' => [],
            'wdd' => [],
            'gln' => [],
        ];

        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return $excluded;
        }

        foreach (Site::query()->get(['fda_establishment_id', 'fda_wdd_facility_id', 'gln']) as $site) {
            if (filled($site->fda_establishment_id)) {
                $excluded['est'][] = (int) $site->fda_establishment_id;
            }

            if (filled($site->fda_wdd_facility_id)) {
                $excluded['wdd'][] = (int) $site->fda_wdd_facility_id;
            }

            if (filled($site->gln)) {
                $excluded['gln'][] = (string) $site->gln;
            }
        }

        return $excluded;
    }

    /** @return array<int, Hidden|Select> */
    public static function establishment(?int $organizationId = null): array
    {
        return self::make(
            FdaEstablishment::class,
            'fda_establishment_id',
            FdaPrefill::establishmentAttributes(...),
            'From FDA establishment',
            constrain: function (Builder $q, Get $get) use ($organizationId): Builder {
                $q->where('is_active', true);

                if ($organizationId !== null) {
                    $q->where('fda_organization_id', $organizationId);
                }

                return $q;
            },
            searchUsing: function (Builder $builder, string $search): array {
                $term = '%'.$search.'%';

                return $builder
                    ->where(function (Builder $query) use ($term): void {
                        $query->where('name', 'like', $term)
                            ->orWhere('firm_name', 'like', $term)
                            ->orWhere('fei_number', 'like', $term)
                            ->orWhere('gln', 'like', $term);
                    })
                    ->orderBy('name')
                    ->limit(self::SEARCH_LIMIT)
                    ->get(['id', 'name', 'firm_name', 'fei_number', 'city', 'state_province'])
                    ->mapWithKeys(fn (FdaEstablishment $est): array => [
                        $est->getKey() => self::formatEstablishmentOption($est),
                    ])
                    ->all();
            },
            optionLabel: fn (FdaEstablishment $est): string => self::formatEstablishmentOption($est),
            optionsUsing: fn (Builder $builder): array => $builder
                ->orderBy('name')
                ->limit(self::SEARCH_LIMIT)
                ->get(['id', 'name', 'firm_name', 'fei_number', 'city', 'state_province'])
                ->mapWithKeys(fn (FdaEstablishment $est): array => [
                    $est->getKey() => self::formatEstablishmentOption($est),
                ])
                ->all(),
            preloadWhenBlank: fn (Get $get): bool => $organizationId !== null,
            afterPick: function (?Model $record, Set $set): void {
                // Switching or clearing establishment invalidates any prior WDD facility pick.
                $set('_fda_pick_fda_wdd_facility_id', null);
                if ($record === null) {
                    $set('fda_wdd_facility_id', null);
                }
                // When an establishment is chosen, FdaPrefill already nulls fda_wdd_facility_id.
            },
        );
    }

    /**
     * WDD facility picker scoped to the FDA organization of the selected establishment
     * when one is chosen. Blank search preloads only when that org scope is active.
     *
     * @return array<int, Hidden|Select>
     */
    public static function wddFacility(?int $organizationId = null): array
    {
        $resolveOrgId = function (Get $get) use ($organizationId): ?int {
            if ($organizationId !== null) {
                return $organizationId;
            }

            $establishmentId = $get('fda_establishment_id') ?: $get('_fda_pick_fda_establishment_id');

            if (blank($establishmentId)) {
                return null;
            }

            $orgId = FdaEstablishment::query()
                ->whereKey($establishmentId)
                ->value('fda_organization_id');

            return $orgId !== null ? (int) $orgId : null;
        };

        return self::make(
            FdaWddFacility::class,
            'fda_wdd_facility_id',
            FdaPrefill::wddFacilityAttributes(...),
            'WDD facility',
            constrain: function (Builder $q, Get $get) use ($resolveOrgId): Builder {
                $q->where('is_active', true);

                $orgId = $resolveOrgId($get);

                if ($orgId !== null) {
                    $q->where('fda_organization_id', $orgId);
                }

                return $q;
            },
            searchUsing: fn (Builder $builder, string $search): array => self::formatFacilityOptions(
                self::filterFacilitiesByTerm($builder, $search)->get([
                    'id', 'name', 'facility_name', 'city', 'state_province', 'gln',
                ]),
            ),
            optionLabel: fn (FdaWddFacility $fac): string => self::formatFacilityOption($fac),
            optionsUsing: fn (Builder $builder): array => self::formatFacilityOptions(
                $builder
                    ->orderBy('name')
                    ->limit(self::SEARCH_LIMIT)
                    ->get(['id', 'name', 'facility_name', 'city', 'state_province', 'gln']),
            ),
            preloadWhenBlank: fn (Get $get): bool => $resolveOrgId($get) !== null,
            configureSelect: function (Select $select): Select {
                return $select
                    ->helperText('Select an establishment first to browse that organization\'s facilities, or type to search all WDD facilities.')
                    ->placeholder('Select a WDD facility…');
            },
        );
    }

    /**
     * Options for the WDD facility select given an optional organization scope and search term.
     *
     * Blank search with an organization returns that org's facilities (dropdown preload).
     * Blank search without an organization returns nothing (avoids dumping the national list).
     * A non-blank search typeahead-filters, still scoped when an organization is set.
     *
     * @return array<int|string, string>
     */
    public static function wddFacilityOptions(?string $search, ?int $organizationId = null): array
    {
        $builder = FdaWddFacility::query()->where('is_active', true);

        if ($organizationId !== null) {
            $builder->where('fda_organization_id', $organizationId);
        }

        if (blank($search)) {
            if ($organizationId === null) {
                return [];
            }

            return self::formatFacilityOptions(
                $builder
                    ->orderBy('name')
                    ->limit(self::SEARCH_LIMIT)
                    ->get(['id', 'name', 'facility_name', 'city', 'state_province', 'gln']),
            );
        }

        return self::formatFacilityOptions(
            self::filterFacilitiesByTerm($builder, $search)
                ->get(['id', 'name', 'facility_name', 'city', 'state_province', 'gln']),
        );
    }

    /** @return array<int, Hidden|Select> */
    public static function packaging(): array
    {
        return self::make(
            FdaProductPackaging::class,
            'fda_product_packaging_id',
            FdaPrefill::packagingAttributes(...),
            'From FDA package',
            constrain: fn (Builder $q, Get $get): Builder => $q->where('is_active', true),
            searchUsing: function (Builder $builder, string $search): array {
                $term = '%'.$search.'%';

                return $builder
                    ->where(function (Builder $query) use ($term): void {
                        $query->where('package_ndc', 'like', $term)
                            ->orWhere('ndc11', 'like', $term)
                            ->orWhere('gtin', 'like', $term)
                            ->orWhereHas('product', function (Builder $product) use ($term): void {
                                $product->where('name', 'like', $term)
                                    ->orWhere('brand_name', 'like', $term)
                                    ->orWhere('generic_name', 'like', $term)
                                    ->orWhere('product_ndc', 'like', $term);
                            });
                    })
                    ->with('product:id,name,brand_name,generic_name')
                    ->orderBy('package_ndc')
                    ->limit(self::SEARCH_LIMIT)
                    ->get()
                    ->mapWithKeys(fn (FdaProductPackaging $pkg): array => [
                        $pkg->getKey() => self::formatPackagingOption($pkg),
                    ])
                    ->all();
            },
            optionLabel: fn (FdaProductPackaging $pkg): string => self::formatPackagingOption($pkg),
            configureSelect: fn (Select $select): Select => $select
                ->helperText('Type to search FDA packages by NDC, GTIN, or product name.')
                ->placeholder('Type to search FDA packages…'),
        );
    }

    public static function tradingPartnerOrganizationOptionLabel(?string $pick): ?string
    {
        $parsed = self::parseTradingPartnerPick($pick);

        if ($parsed === null) {
            return null;
        }

        return match ($parsed['type']) {
            'org' => ($organization = FdaOrganization::query()->find($parsed['id']))
                ? self::formatTradingPartnerOrganizationOption($organization)
                : null,
            'est' => ($establishment = FdaEstablishment::query()->find($parsed['id']))
                ? self::formatTradingPartnerEstablishmentOption($establishment)
                : null,
            'wdd' => ($facility = FdaWddFacility::query()->find($parsed['id']))
                ? self::formatTradingPartnerFacilityOption($facility)
                : null,
        };
    }

    public static function tradingPartnerCreatePreview(?string $pick, ?string $partnerName, ?string $partnerGln = null): ?string
    {
        if (blank($pick) || blank($partnerName)) {
            return null;
        }

        $parsed = self::parseTradingPartnerPick($pick);

        if ($parsed === null) {
            return null;
        }

        $hqName = (DisplayName::clean($partnerName) ?? $partnerName).' - HQ Site';
        $lines = [
            'Creating '.$partnerName.' as a trading partner',
            'Headquarters: '.$hqName,
        ];

        $extra = self::tradingPartnerPreviewExtraLine($parsed, $partnerGln);

        if ($extra !== null) {
            $lines[] = $extra;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{type: 'org'|'est'|'wdd', id: int}  $parsed
     */
    private static function tradingPartnerPreviewExtraLine(array $parsed, ?string $partnerGln): ?string
    {
        $organization = self::resolveTradingPartnerOrganization(
            $parsed['type'].':'.$parsed['id'],
        );
        $hqGln = filled($partnerGln) ? $partnerGln : $organization?->gln;

        if ($parsed['type'] === 'est') {
            $establishment = FdaEstablishment::query()->find($parsed['id']);
            $siteName = (string) ($establishment?->name ?: $establishment?->firm_name ?: 'Plant');

            if ($establishment === null || self::pickedLocationSharesHqGln($hqGln, $establishment->gln)) {
                return null;
            }

            return 'Also: '.$siteName.' (plant)';
        }

        if ($parsed['type'] === 'wdd') {
            $facility = FdaWddFacility::query()->find($parsed['id']);
            $siteName = (string) ($facility?->name ?: $facility?->facility_name ?: 'Warehouse');

            if ($facility === null || self::pickedLocationSharesHqGln($hqGln, $facility->gln)) {
                return null;
            }

            return 'Also: '.$siteName.' (warehouse)';
        }

        return null;
    }

    public static function pickedLocationSharesHqGln(?string $organizationGln, ?string $locationGln): bool
    {
        return filled($organizationGln) && filled($locationGln) && $organizationGln === $locationGln;
    }

    /**
     * @return array{type: 'org'|'est'|'wdd', id: int}|null
     */
    public static function parseTradingPartnerPick(?string $pick): ?array
    {
        if (! is_string($pick) || ! preg_match('/^(org|est|wdd):(\d+)$/', $pick, $matches)) {
            return null;
        }

        return [
            'type' => $matches[1],
            'id' => (int) $matches[2],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FdaOrganization>|\Illuminate\Database\Eloquent\Collection<int, FdaOrganization>  $organizations
     * @return array<string, string>
     */
    private static function formatTradingPartnerOrganizationOptions($organizations): array
    {
        return $organizations
            ->mapWithKeys(fn (FdaOrganization $org): array => [
                'org:'.$org->getKey() => self::formatTradingPartnerOrganizationOption($org),
            ])
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FdaEstablishment>|\Illuminate\Database\Eloquent\Collection<int, FdaEstablishment>  $establishments
     * @return array<string, string>
     */
    private static function formatTradingPartnerEstablishmentOptions($establishments): array
    {
        return $establishments
            ->mapWithKeys(fn (FdaEstablishment $est): array => [
                'est:'.$est->getKey() => self::formatTradingPartnerEstablishmentOption($est),
            ])
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FdaWddFacility>|\Illuminate\Database\Eloquent\Collection<int, FdaWddFacility>  $facilities
     * @return array<string, string>
     */
    private static function formatTradingPartnerFacilityOptions($facilities): array
    {
        return $facilities
            ->mapWithKeys(fn (FdaWddFacility $fac): array => [
                'wdd:'.$fac->getKey() => self::formatTradingPartnerFacilityOption($fac),
            ])
            ->all();
    }

    private static function formatTradingPartnerOrganizationOption(FdaOrganization $organization): string
    {
        $name = (string) ($organization->name ?: $organization->original_name ?: $organization->canonical_name);

        return self::formatTradingPartnerHit($name, self::organizationAddressLine($organization), 'Company');
    }

    private static function formatTradingPartnerEstablishmentOption(FdaEstablishment $establishment): string
    {
        $name = (string) ($establishment->name ?: $establishment->firm_name ?: 'Establishment');

        return self::formatTradingPartnerHit($name, self::registryAddressLine($establishment), 'Plant');
    }

    private static function formatTradingPartnerFacilityOption(FdaWddFacility $facility): string
    {
        $name = (string) ($facility->name ?: $facility->facility_name ?: 'Facility');

        return self::formatTradingPartnerHit($name, self::registryAddressLine($facility), 'Warehouse');
    }

    private static function formatTradingPartnerHit(string $name, string $address, string $type): string
    {
        return self::formatNameAndAddress($name, $address)
            .'<br><span class="text-xs opacity-70">'.e($type).'</span>';
    }

    private static function filterEstablishmentsByTerm(Builder $builder, string $search): Builder
    {
        $term = '%'.$search.'%';

        return $builder
            ->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('firm_name', 'like', $term)
                    ->orWhere('fei_number', 'like', $term)
                    ->orWhere('gln', 'like', $term)
                    ->orWhere('duns_number', 'like', $term)
                    ->orWhere('street_address', 'like', $term)
                    ->orWhere('street_address_2', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('state_province', 'like', $term)
                    ->orWhere('postal_code', 'like', $term)
                    ->orWhere('full_address', 'like', $term);
            })
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT);
    }

    private static function filterTradingPartnerFacilitiesByTerm(Builder $builder, string $search): Builder
    {
        $term = '%'.$search.'%';

        return $builder
            ->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('facility_name', 'like', $term)
                    ->orWhere('alternate_name', 'like', $term)
                    ->orWhere('gln', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('street_address', 'like', $term)
                    ->orWhere('street_address_2', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('state_province', 'like', $term)
                    ->orWhere('postal_code', 'like', $term)
                    ->orWhere('full_address', 'like', $term);
            })
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT);
    }

    private static function formatOrganizationOption(FdaOrganization $organization): string
    {
        $name = (string) ($organization->name ?: $organization->original_name ?: $organization->canonical_name);

        return self::formatNameAndAddress($name, self::organizationAddressLine($organization));
    }

    private static function formatNameAndAddress(string $name, string $address): string
    {
        $nameHtml = '<strong>'.e($name).'</strong>';

        if ($address === '') {
            return $nameHtml;
        }

        return $nameHtml.'<br>'.e($address);
    }

    private static function organizationAddressLine(FdaOrganization $organization): string
    {
        return self::registryAddressLine($organization);
    }

    private static function registryAddressLine(object $record): string
    {
        if (filled($record->full_address ?? null)) {
            return trim((string) $record->full_address);
        }

        $hasPlace = filled($record->street_address ?? null)
            || filled($record->street_address_2 ?? null)
            || filled($record->city ?? null)
            || filled($record->state_province ?? null)
            || filled($record->postal_code ?? null);

        if (! $hasPlace) {
            return '';
        }

        $cityStateZip = implode(', ', array_filter([
            filled($record->city ?? null) ? (string) $record->city : null,
            filled($record->state_province ?? null) ? (string) $record->state_province : null,
        ]));

        if (filled($record->postal_code ?? null)) {
            $cityStateZip = trim($cityStateZip.' '.$record->postal_code);
        }

        return implode(', ', array_filter([
            filled($record->street_address ?? null) ? (string) $record->street_address : null,
            filled($record->street_address_2 ?? null) ? (string) $record->street_address_2 : null,
            $cityStateZip !== '' ? $cityStateZip : null,
            filled($record->country_code ?? null) ? (string) $record->country_code : null,
        ]));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FdaOrganization>|\Illuminate\Database\Eloquent\Collection<int, FdaOrganization>  $organizations
     * @return array<int|string, string>
     */
    private static function formatOrganizationOptions($organizations): array
    {
        return $organizations
            ->mapWithKeys(fn (FdaOrganization $org): array => [
                $org->getKey() => self::formatOrganizationOption($org),
            ])
            ->all();
    }

    private static function filterOrganizationsByTerm(Builder $builder, string $search): Builder
    {
        $term = '%'.$search.'%';

        return $builder
            ->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('canonical_name', 'like', $term)
                    ->orWhere('original_name', 'like', $term)
                    ->orWhere('gln', 'like', $term)
                    ->orWhere('duns_number', 'like', $term)
                    ->orWhere('street_address', 'like', $term)
                    ->orWhere('street_address_2', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('state_province', 'like', $term)
                    ->orWhere('postal_code', 'like', $term)
                    ->orWhere('full_address', 'like', $term);
            })
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT);
    }

    private static function formatEstablishmentOption(FdaEstablishment $establishment): string
    {
        $label = DisplayName::clean($establishment->name ?: $establishment->firm_name) ?: 'Establishment';

        if (filled($establishment->fei_number)) {
            $label .= ' — FEI '.$establishment->fei_number;
        }

        return $label;
    }

    private static function formatFacilityOption(FdaWddFacility $facility): string
    {
        $label = DisplayName::clean($facility->name ?: $facility->facility_name) ?: 'Facility';
        $location = collect([
            DisplayName::clean($facility->city),
            $facility->state_province,
        ])->filter()->implode(', ');

        if (filled($location)) {
            $label .= ' — '.$location;
        }

        return $label;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FdaWddFacility>|\Illuminate\Database\Eloquent\Collection<int, FdaWddFacility>  $facilities
     * @return array<int|string, string>
     */
    private static function formatFacilityOptions($facilities): array
    {
        return $facilities
            ->mapWithKeys(fn (FdaWddFacility $fac): array => [
                $fac->getKey() => self::formatFacilityOption($fac),
            ])
            ->all();
    }

    private static function filterFacilitiesByTerm(Builder $builder, string $search): Builder
    {
        $term = '%'.$search.'%';

        return $builder
            ->where(function (Builder $query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('facility_name', 'like', $term)
                    ->orWhere('gln', 'like', $term)
                    ->orWhere('code', 'like', $term);
            })
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT);
    }

    private static function formatPackagingOption(FdaProductPackaging $packaging): string
    {
        $listing = $packaging->relationLoaded('product') ? $packaging->product : $packaging->product()->first();
        $label = DisplayName::clean($listing?->name ?: $listing?->brand_name ?: $listing?->generic_name) ?: 'Package';

        if (filled($packaging->package_ndc)) {
            $label .= ' — '.$packaging->package_ndc;
        }

        if (filled($packaging->gtin)) {
            $label .= ' · '.$packaging->gtin;
        }

        return $label;
    }
}
