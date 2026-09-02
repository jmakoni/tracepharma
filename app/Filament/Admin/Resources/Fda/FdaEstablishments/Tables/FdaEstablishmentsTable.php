<?php

namespace App\Filament\Admin\Resources\Fda\FdaEstablishments\Tables;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FdaEstablishmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['organization', 'operations']))
            ->columns([
                FdaRegistryBadges::identifierColumn('fei_number', 'FEI'),
                TextColumn::make('organization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->placeholder(fn ($record) => $record->firm_name),
                TextColumn::make('city')->searchable(),
                TextColumn::make('state_province')->label('State')->searchable(),
                FdaRegistryBadges::identifierColumn('gln', 'GLN')->toggleable(isToggledHiddenByDefault: true),
                FdaRegistryBadges::identifierColumn('duns_number', 'DUNS')->toggleable(isToggledHiddenByDefault: true),
                FdaRegistryBadges::identifierColumn('dea_number', 'DEA')->toggleable(isToggledHiddenByDefault: true),
                FdaRegistryBadges::identifierColumn('hin_number', 'HIN')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('country_code')->label('Country'),
                TextColumn::make('operations.operation_code')
                    ->label('Operations')
                    ->badge()
                    ->limitList(3),
                FdaRegistryBadges::establishmentColumn(),
                FdaRegistryBadges::activeColumn(),
            ])
            ->defaultSort('fei_number')
            ->searchPlaceholder('FEI, name, or organization')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }
}
