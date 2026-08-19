<?php

namespace App\Filament\App\Resources\Devices\Tables;

use App\Enums\DeviceType;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Support\Catalog\DisplayName;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('site'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('device_type')->badge(),
                TextColumn::make('manufacturer')
                    ->toggleable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state)),
                TextColumn::make('model')->toggleable(),
                TextColumn::make('site.name')->label('Site')->toggleable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('Name, manufacturer, or model')
            ->filters([
                TernaryFilter::make('is_active')->default(true),
                SelectFilter::make('device_type')
                    ->options(collect(DeviceType::cases())->mapWithKeys(
                        fn (DeviceType $type) => [$type->value => $type->label()]
                    )),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    RegulatoryCompliance::apply(
                        DeleteBulkAction::make(),
                        'devices_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }
}
