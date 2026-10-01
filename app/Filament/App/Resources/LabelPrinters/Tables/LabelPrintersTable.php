<?php

namespace App\Filament\App\Resources\LabelPrinters\Tables;

use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\LabelPrinter;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class LabelPrintersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(LabelPrinter::class, 'name')),
                    ),
                TextColumn::make('ip_address')
                    ->label('Address')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('ip_address')
                            ->options(fn (): array => DistinctColumnOptions::of(LabelPrinter::class, 'ip_address')),
                    ),
                TextColumn::make('port')
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('protocol')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('protocol')
                            ->options(fn (): array => DistinctColumnOptions::of(LabelPrinter::class, 'protocol')),
                    ),
                IconColumn::make('is_default')
                    ->boolean()
                    ->label('Default')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_default')
                            ->options(DistinctColumnOptions::boolean()),
                    ),
                IconColumn::make('enabled')
                    ->boolean()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('enabled')
                            ->options(DistinctColumnOptions::boolean()),
                    ),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('enabled')->default(true),
                TernaryFilter::make('is_default')->label('Default'),
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
                        'label_printers_bulk_delete',
                        requireReason: true,
                    ),
                ]),
            ]);
    }
}
