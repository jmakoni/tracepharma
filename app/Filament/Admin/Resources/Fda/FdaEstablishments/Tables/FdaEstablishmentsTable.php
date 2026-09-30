<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\Tables;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class FdaEstablishmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organization', 'operations']))
            ->columns([
                FdaRegistryBadges::identifierColumn('fei_number', 'FEI')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('fei_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'fei_number')),
                    ),
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('fda_organization_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                FdaEstablishment::class,
                                'fda_organization_id',
                                FdaOrganization::class,
                            )),
                    ),
                TextColumn::make('name')
                    ->searchable()
                    ->placeholder(fn ($record) => $record->firm_name)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'name')),
                    ),
                TextColumn::make('street_address')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('street_address')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'street_address')),
                    ),
                TextColumn::make('city')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('city')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'city')),
                    ),
                TextColumn::make('state_province')
                    ->label('State')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('state_province')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'state_province')),
                    ),
                FdaRegistryBadges::identifierColumn('gln', 'GLN')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('gln')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'gln')),
                    ),
                FdaRegistryBadges::identifierColumn('duns_number', 'DUNS')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('duns_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'duns_number')),
                    ),
                FdaRegistryBadges::identifierColumn('dea_number', 'DEA')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('dea_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'dea_number')),
                    ),
                FdaRegistryBadges::identifierColumn('hin_number', 'HIN')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('hin_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'hin_number')),
                    ),
                FdaRegistryBadges::identifierColumn('chemical_reg_number', 'Chemical Reg')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('chemical_reg_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'chemical_reg_number')),
                    ),
                TextColumn::make('country_code')
                    ->label('Country')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('country_code')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaEstablishment::class, 'country_code')),
                    ),
                TextColumn::make('operations.operation_code')
                    ->label('Operations')
                    ->badge()
                    ->limitList(3),
                FdaRegistryBadges::establishmentColumn(),
                FdaRegistryBadges::activeColumn()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
            ])
            ->defaultSort('fei_number')
            ->searchPlaceholder('FEI, name, organization, or street')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }
}
