<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\Tables;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class FdaProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('fdaOrganization')
                ->withCount('packaging'))
            ->columns([
                FdaRegistryBadges::identifierColumn('product_ndc', 'NDC')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('product_ndc')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaProduct::class, 'product_ndc')),
                    ),
                TextColumn::make('name')
                    ->searchable()
                    ->limit(40)
                    ->placeholder(fn (FdaProduct $record): ?string => $record->brand_name ?: $record->generic_name)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaProduct::class, 'name')),
                    ),
                TextColumn::make('fdaOrganization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('fda_organization_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                FdaProduct::class,
                                'fda_organization_id',
                                FdaOrganization::class,
                            )),
                    ),
                TextColumn::make('dosage_form')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('dosage_form')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaProduct::class, 'dosage_form')),
                    ),
                FdaRegistryBadges::productKindColumn(),
                FdaRegistryBadges::deaColumn()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('dea_schedule')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaProduct::class, 'dea_schedule')),
                    ),
                TextColumn::make('packaging_count')
                    ->label('Packages')
                    ->sortable()
                    ->columnFilter(ColumnFilter::range()),
                FdaRegistryBadges::activeColumn()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
            ])
            ->defaultSort('product_ndc')
            ->searchPlaceholder('NDC, name, brand, or generic')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }
}
