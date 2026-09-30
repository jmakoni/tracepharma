<?php

namespace App\Filament\Admin\Resources\Fda\FdaImportRuns\Tables;

use App\Filament\Admin\Support\FdaRegistryBadges;
use App\Filament\Support\RecordActionGroup;
use App\Models\Fda\FdaImportRun;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class FdaImportRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('source')
                            ->options(fn (): array => DistinctColumnOptions::of(FdaImportRun::class, 'source')),
                    ),
                FdaRegistryBadges::importOutcomeColumn(),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable()
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('rows_read')
                    ->label('Read')
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('rows_inserted')
                    ->label('Inserted')
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('rows_updated')
                    ->label('Updated')
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('rows_skipped')
                    ->label('Skipped')
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('rows_sent_to_review')
                    ->label('To review')
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? '—'
                        : number_format($state / 1000, 1).'s')
                    ->columnFilter(ColumnFilter::range()),
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
