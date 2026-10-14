<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\Tables;

use App\Enums\PartnerType;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaOrganization;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class FdaOrganizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'establishments',
                'wddFacilities',
                'products',
            ]))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record): ?string => $record->canonical_name)
                    ->formatStateUsing(fn (mixed $state, $record): string => filled($state)
                        ? (string) $state
                        : (string) ($record->original_name ?? ''))
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaOrganization::class, 'name')),
                    ),
                FdaRegistryBadges::partnerTypeColumn()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('partner_type')
                            ->options(DistinctColumnOptions::enum(PartnerType::class)),
                    ),
                FdaRegistryBadges::identifierColumn('gln', 'GLN')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('gln')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaOrganization::class, 'gln')),
                    ),
                FdaRegistryBadges::identifierColumn('duns_number', 'DUNS')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('duns_number')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaOrganization::class, 'duns_number')),
                    ),
                TextColumn::make('street_address')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('street_address')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaOrganization::class, 'street_address')),
                    ),
                TextColumn::make('establishments_count')
                    ->label('Establishments')
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::range()
                            ->applyUsing(fn (Builder $query, array $data): Builder => DistinctColumnOptions::applyHavingRange($query, $data, 'establishments_count')),
                    ),
                TextColumn::make('wdd_facilities_count')
                    ->label('WDD facilities')
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::range()
                            ->applyUsing(fn (Builder $query, array $data): Builder => DistinctColumnOptions::applyHavingRange($query, $data, 'wdd_facilities_count')),
                    ),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::range()
                            ->applyUsing(fn (Builder $query, array $data): Builder => DistinctColumnOptions::applyHavingRange($query, $data, 'products_count')),
                    ),
                FdaRegistryBadges::activeColumn()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name, GLN, DUNS, or street')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }
}
