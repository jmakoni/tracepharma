<?php

namespace App\Filament\App\Resources\LabelPrinters\Tables;

use App\Filament\Support\RecordActionGroup;
use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class LabelPrintersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('ip_address')
                    ->label('Address')
                    ->searchable(),
                TextColumn::make('port'),
                TextColumn::make('protocol')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state),
                IconColumn::make('is_default')
                    ->boolean()
                    ->label('Default'),
                IconColumn::make('enabled')
                    ->boolean(),
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
