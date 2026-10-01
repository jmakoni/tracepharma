<?php

namespace App\Filament\App\Resources\LocationDevices\Tables;

use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\LocationDevice;
use App\Models\Site;
use App\Support\Catalog\DisplayName;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class LocationDevicesTable
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
                            ->options(fn (): array => DistinctColumnOptions::of(LocationDevice::class, 'name')),
                    ),
                TextColumn::make('gln')
                    ->label('GLN')
                    ->searchable()
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('gln')
                            ->options(fn (): array => DistinctColumnOptions::of(LocationDevice::class, 'gln')),
                    ),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('site_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                LocationDevice::class,
                                'site_id',
                                Site::class,
                            )),
                    ),
                TextColumn::make('sgln')
                    ->label('SGLN')
                    ->copyable()
                    ->fontFamily(FontFamily::Mono)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('sgln')
                            ->options(fn (): array => DistinctColumnOptions::of(LocationDevice::class, 'sgln')),
                    ),
            ])
            ->defaultSort('name')
            ->searchPlaceholder('GLN or device name')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([EditAction::make()]))
            ->toolbarActions([
                BulkActionGroup::make([
                    RegulatoryCompliance::apply(
                        DeleteBulkAction::make(),
                        'location_devices_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }
}
