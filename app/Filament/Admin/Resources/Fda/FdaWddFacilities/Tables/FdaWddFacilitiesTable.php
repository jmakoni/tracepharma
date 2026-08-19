<?php

namespace App\Filament\Admin\Resources\Fda\FdaWddFacilities\Tables;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('organization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->placeholder(fn ($record) => $record->facility_name),
                FdaRegistryBadges::facilityTypeColumn(),
                TextColumn::make('city')->searchable(),
                TextColumn::make('state_province')->label('State'),
                TextColumn::make('country_code')->label('Country'),
                TextColumn::make('active_licenses_count')->label('Active licenses')->sortable(),
                TextColumn::make('soonest_expiration_date')
                    ->label('Soonest expiration')
                    ->date()
                    ->sortable()
                    ->placeholder('—'),
                FdaRegistryBadges::activeColumn(),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name, GLN, or organization')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }
}
