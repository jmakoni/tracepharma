<?php

namespace App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Tables;

use App\Enums\FacilityType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plStaging;
use App\Support\Fda\FdaDate;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class FdaWdd3plStagingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['fdaOrganization']))
            ->columns([
                TextColumn::make('facility_name')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('facility_name')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWdd3plStaging::class, 'facility_name')),
                    ),
                TextColumn::make('fdaOrganization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('fda_organization_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                FdaWdd3plStaging::class,
                                'fda_organization_id',
                                FdaOrganization::class,
                            )),
                    ),
                TextColumn::make('facility_type')
                    ->badge()
                    ->toggleable()
                    ->columnFilter(ColumnFilter::select()->syncWith('facility_type')),
                TextColumn::make('license_number')
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('license_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWdd3plStaging::class, 'license_number')),
                    ),
                TextColumn::make('license_state')
                    ->columnFilter(ColumnFilter::select()->syncWith('license_state')),
                TextColumn::make('state')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('state')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWdd3plStaging::class, 'state')),
                    ),
                TextColumn::make('street_address')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('street_address')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWdd3plStaging::class, 'street_address')),
                    ),
                TextColumn::make('city')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('city')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWdd3plStaging::class, 'city')),
                    ),
                TextColumn::make('reporting_year')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('reporting_year')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWdd3plStaging::class, 'reporting_year')),
                    ),
                TextColumn::make('contact_phone')
                    ->label('Contact phone')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('contact_phone')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWdd3plStaging::class, 'contact_phone')),
                    ),
                TextColumn::make('expiration_date')
                    ->formatStateUsing(fn (?string $state): ?string => FdaDate::display($state))
                    ->toggleable()
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->defaultSort('expiration_date')
            ->filters([
                Filter::make('incomplete_license_fields')
                    ->label('Incomplete license fields')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->missingPromoteFields()),
                SelectFilter::make('facility_type')
                    ->options(collect(FacilityType::cases())->mapWithKeys(
                        fn (FacilityType $type) => [$type->value => $type->label()]
                    )),
                SelectFilter::make('license_state')
                    ->searchable()
                    ->getSearchResultsUsing(fn (?string $search): array => blank($search) ? [] : FdaWdd3plStaging::query()
                        ->whereNotNull('license_state')
                        ->where('license_state', 'like', '%'.$search.'%')
                        ->distinct()
                        ->orderBy('license_state')
                        ->limit(50)
                        ->pluck('license_state', 'license_state')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (string) $value : null),
            ], FiltersLayout::AboveContentCollapsible)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks();
    }
}
