<?php

namespace App\Filament\Admin\Resources\Fda\FdaImportRuns\Tables;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FdaImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source')->searchable()->sortable(),
                FdaRegistryBadges::importOutcomeColumn(),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('completed_at')->dateTime()->placeholder('—'),
                TextColumn::make('rows_read')->label('Read'),
                TextColumn::make('rows_inserted')->label('Inserted'),
                TextColumn::make('rows_updated')->label('Updated'),
                TextColumn::make('rows_skipped')->label('Skipped'),
                TextColumn::make('rows_sent_to_review')->label('To review'),
                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? '—'
                        : number_format($state / 1000, 1).'s'),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }
}
