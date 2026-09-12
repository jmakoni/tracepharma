<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SerializationLots\Tables;

use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    ->sortable(),
                TextColumn::make('product_name')
                    ->label('Product')
                    ->searchable()
                    ->limit(28)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—'),
                TextColumn::make('ndc')
                    ->label('NDC')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('unit_gtin14')
                    ->label('Unit GTIN')
                    ->fontFamily(FontFamily::Mono)
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expire_date')
                    ->label('Expiry')
                    ->date()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('pallet_count')
                    ->label('Pallets')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('case_count')
                    ->label('Cases')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('unit_count')
                    ->label('Units')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'accepted' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('lot_processed_at')
                    ->label('Processed')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
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
