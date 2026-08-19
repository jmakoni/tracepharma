<?php

namespace App\Filament\Admin\Resources\Fda\FdaProducts\Tables;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaProduct;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FdaProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('fdaOrganization')
                ->withCount('packaging'))
            ->columns([
                FdaRegistryBadges::identifierColumn('product_ndc', 'NDC'),
                TextColumn::make('name')
                    ->searchable()
                    ->limit(40)
                    ->placeholder(fn (FdaProduct $record): ?string => $record->brand_name ?: $record->generic_name),
                TextColumn::make('fdaOrganization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('dosage_form')->toggleable(),
                FdaRegistryBadges::productKindColumn(),
                FdaRegistryBadges::deaColumn(),
                TextColumn::make('packaging_count')->label('Packages')->sortable(),
                FdaRegistryBadges::activeColumn(),
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
