<?php

namespace App\Filament\App\Resources\Devices\Tables;

use App\Enums\DeviceType;
use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Device;
use App\Models\Site;
use App\Support\Catalog\DisplayName;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

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
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state))
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(Device::class, 'name')),
                    ),
                TextColumn::make('device_type')
                    ->badge()
                    ->columnFilter(ColumnFilter::select()->syncWith('device_type')),
                TextColumn::make('manufacturer')
                    ->toggleable()
                    ->formatStateUsing(fn (?string $state): ?string => DisplayName::clean($state))
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('manufacturer')
                            ->options(fn (): array => DistinctColumnOptions::of(Device::class, 'manufacturer')),
                    ),
                TextColumn::make('model')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('model')
                            ->options(fn (): array => DistinctColumnOptions::of(Device::class, 'model')),
                    ),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('site_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                Device::class,
                                'site_id',
                                Site::class,
                            )),
                    ),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?bool $state): string => $state ? 'Active' : 'Inactive')
                    ->color(fn (?bool $state): string => $state ? 'success' : 'gray')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean()),
                    ),
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
