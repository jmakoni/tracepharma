<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\Tables;

use App\Enums\FacilityType;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class FdaWddFacilitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('organization')
                ->withCount([
                    'licenses as active_licenses_count' => fn (Builder $licenses) => $licenses->where('is_active', true),
                ])
                ->withMin([
                    'licenses as soonest_expiration_date' => fn (Builder $licenses) => $licenses->where('is_active', true),
                ], 'expiration_date'))
            ->columns([
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('fda_organization_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                FdaWddFacility::class,
                                'fda_organization_id',
                                FdaOrganization::class,
                            )),
                    ),
                TextColumn::make('name')
                    ->searchable()
                    ->placeholder(fn ($record) => $record->facility_name)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'name')),
                    ),
                FdaRegistryBadges::facilityTypeColumn()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('facility_type')
                            ->options(DistinctColumnOptions::enum(FacilityType::class)),
                    ),
                TextColumn::make('street_address')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('street_address')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'street_address')),
                    ),
                TextColumn::make('city')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('city')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'city')),
                    ),
                TextColumn::make('state_province')
                    ->label('State')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('state_province')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'state_province')),
                    ),
                FdaRegistryBadges::identifierColumn('gln', 'GLN')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('gln')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'gln')),
                    ),
                FdaRegistryBadges::identifierColumn('duns_number', 'DUNS')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('duns_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'duns_number')),
                    ),
                FdaRegistryBadges::identifierColumn('dea_number', 'DEA')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('dea_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'dea_number')),
                    ),
                FdaRegistryBadges::identifierColumn('hin_number', 'HIN')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('hin_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'hin_number')),
                    ),
                FdaRegistryBadges::identifierColumn('chemical_reg_number', 'Chemical Reg')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('chemical_reg_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'chemical_reg_number')),
                    ),
                TextColumn::make('country_code')
                    ->label('Country')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('country_code')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaWddFacility::class, 'country_code')),
                    ),
                TextColumn::make('active_licenses_count')
                    ->label('Active licenses')
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::range()
                            ->applyUsing(fn (Builder $query, array $data): Builder => DistinctColumnOptions::applyHavingRange($query, $data, 'active_licenses_count')),
                    ),
                TextColumn::make('soonest_expiration_date')
                    ->label('Soonest expiration')
                    ->date()
                    ->sortable()
                    ->placeholder('—')
                    ->columnFilter(
                        ColumnFilter::date()
                            ->applyUsing(fn (Builder $query, array $data): Builder => DistinctColumnOptions::applyHavingDate($query, $data, 'soonest_expiration_date')),
                    ),
                FdaRegistryBadges::activeColumn()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name, GLN, organization, or street')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }
}
