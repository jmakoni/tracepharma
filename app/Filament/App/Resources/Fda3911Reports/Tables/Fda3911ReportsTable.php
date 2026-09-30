<?php

namespace App\Filament\App\Resources\Fda3911Reports\Tables;

use App\Enums\Fda3911ReportStatus;
use App\Models\Fda3911Report;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class Fda3911ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product_name')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('product_name')
                            ->options(fn (): array => DistinctColumnOptions::of(Fda3911Report::class, 'product_name')),
                    ),
                TextColumn::make('product_gtin')
                    ->label('GTIN')
                    ->searchable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('product_gtin')
                            ->options(fn (): array => DistinctColumnOptions::of(Fda3911Report::class, 'product_gtin')),
                    ),
                TextColumn::make('serial')
                    ->searchable()
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('serial')
                            ->options(fn (): array => DistinctColumnOptions::of(Fda3911Report::class, 'serial')),
                    ),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (Fda3911ReportStatus $state): string => $state->label())
                    ->color(fn (Fda3911ReportStatus $state): string => $state->color())
                    ->columnFilter(ColumnFilter::select()->syncWith('status')),
                TextColumn::make('due_at')
                    ->dateTime()
                    ->sortable()
                    ->color(fn (Fda3911Report $record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('incident_number')
                    ->placeholder('—')
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('incident_number')
                            ->options(fn (): array => DistinctColumnOptions::of(Fda3911Report::class, 'incident_number')),
                    ),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(Fda3911ReportStatus::cases())->mapWithKeys(
                        fn (Fda3911ReportStatus $status): array => [$status->value => $status->label()]
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
