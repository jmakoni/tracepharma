<?php

namespace App\Filament\App\Resources\Fda3911Reports\Tables;

use App\Enums\Fda3911ReportStatus;
use App\Models\Fda3911Report;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class Fda3911ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product_name')
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('product_gtin')
                    ->label('GTIN')
                    ->searchable(),
                TextColumn::make('serial')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (Fda3911ReportStatus $state): string => $state->label())
                    ->color(fn (Fda3911ReportStatus $state): string => $state->color()),
                TextColumn::make('due_at')
                    ->dateTime()
                    ->sortable()
                    ->color(fn (Fda3911Report $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('incident_number')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
