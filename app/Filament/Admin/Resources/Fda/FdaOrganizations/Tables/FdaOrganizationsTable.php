<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\Tables;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                        : (string) ($record->original_name ?? '')),
                FdaRegistryBadges::partnerTypeColumn(),
                FdaRegistryBadges::identifierColumn('gln', 'GLN'),
                FdaRegistryBadges::identifierColumn('duns_number', 'DUNS'),
                TextColumn::make('establishments_count')->label('Establishments')->sortable(),
                TextColumn::make('wdd_facilities_count')->label('WDD facilities')->sortable(),
                TextColumn::make('products_count')->label('Products')->sortable(),
                FdaRegistryBadges::activeColumn(),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name, GLN, or DUNS')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }
}
