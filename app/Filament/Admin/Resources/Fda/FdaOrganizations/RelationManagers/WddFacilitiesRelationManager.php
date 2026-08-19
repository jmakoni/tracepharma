<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizations\RelationManagers;

use App\Filament\Admin\Resources\Fda\FdaWddFacilities\FdaWddFacilityResource;
use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WddFacilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'wddFacilities';

    protected static ?string $title = 'WDD Facilities';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'licenses as active_licenses_count' => fn (Builder $licenses) => $licenses->where('is_active', true),
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->placeholder(fn ($record) => $record->facility_name),
                FdaRegistryBadges::facilityTypeColumn(),
                TextColumn::make('city'),
                TextColumn::make('state_province')->label('State'),
                TextColumn::make('active_licenses_count')->label('Active licenses'),
                FdaRegistryBadges::activeColumn(),
            ])
            ->recordUrl(fn ($record): string => FdaWddFacilityResource::getUrl('view', ['record' => $record]))
            ->recordActions(RecordActionGroup::make([
                ViewAction::make()
                    ->url(fn ($record): string => FdaWddFacilityResource::getUrl('view', ['record' => $record])),
                EditAction::make()
                    ->url(fn ($record): string => FdaWddFacilityResource::getUrl('edit', ['record' => $record])),
            ]));
    }
}
