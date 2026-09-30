<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SerializationLots\Tables;

use App\Models\L3\SerializationLot;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class SerializationLotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->select([
                'id',
                'feed_id',
                'epcis_document_id',
                'lot_number',
                'ndc',
                'unit_gtin14',
                'case_gtin14',
                'product_name',
                'expire_date',
                'mfg_date',
                'site_id',
                'line_name',
                'lot_processed_at',
                'timezone_offset',
                'lot_info_saved_at',
                'pallet_count',
                'case_count',
                'unit_count',
                'status',
                'created_at',
                'updated_at',
            ]))
            ->columns([
                TextColumn::make('lot_number')
                    ->label('Lot number')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('lot_number')
                            ->options(fn (): array => DistinctColumnOptions::of(SerializationLot::class, 'lot_number')),
                    ),
                TextColumn::make('product_name')
                    ->label('Product')
                    ->searchable()
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('product_name')
                            ->options(fn (): array => DistinctColumnOptions::of(SerializationLot::class, 'product_name')),
                    ),
                TextColumn::make('ndc')
                    ->label('NDC')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('ndc')
                            ->options(fn (): array => DistinctColumnOptions::of(SerializationLot::class, 'ndc')),
                    ),
                TextColumn::make('unit_gtin14')
                    ->label('Unit GTIN')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('unit_gtin14')
                            ->options(fn (): array => DistinctColumnOptions::of(SerializationLot::class, 'unit_gtin14')),
                    ),
                TextColumn::make('expire_date')
                    ->label('Expiry')
                    ->date()
                    ->sortable()
                    ->placeholder('—')
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('pallet_count')
                    ->label('Pallets')
                    ->numeric()
                    ->alignEnd()
                    ->sortable()
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('case_count')
                    ->label('Cases')
                    ->numeric()
                    ->alignEnd()
                    ->sortable()
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('unit_count')
                    ->label('Units')
                    ->numeric()
                    ->alignEnd()
                    ->sortable()
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'accepted' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    })
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('status')
                            ->options(fn (): array => DistinctColumnOptions::of(SerializationLot::class, 'status')),
                    ),
                TextColumn::make('lot_processed_at')
                    ->label('Processed')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->defaultSort('lot_processed_at', 'desc')
            ->searchDebounce('500ms')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
