<?php

namespace App\Filament\Support\ActivityLogs;

use App\Filament\Support\RecordActionGroup;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

final class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['causer', 'subject']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap()
                    ->limit(80)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('description')
                            ->options(fn (): array => DistinctColumnOptions::of(Activity::class, 'description')),
                    ),
                TextColumn::make('event')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(ColumnFilter::select()->syncWith('event')),
                TextColumn::make('log_name')
                    ->label('Log')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->columnFilter(ColumnFilter::select()->syncWith('log_name')),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state, Model $record): string => self::formatMorph($state, $record->getAttribute('subject_id')))
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('subject_type')
                            ->options(fn (): array => DistinctColumnOptions::of(Activity::class, 'subject_type')),
                    ),
                TextColumn::make('causer_type')
                    ->label('Causer')
                    ->formatStateUsing(function (?string $state, Model $record): string {
                        $causer = $record->causer;
                        if ($causer !== null && filled($causer->getAttribute('email'))) {
                            return (string) $causer->getAttribute('email');
                        }
                        if ($causer !== null && filled($causer->getAttribute('name'))) {
                            return (string) $causer->getAttribute('name');
                        }

                        return self::formatMorph($state, $record->getAttribute('causer_id'));
                    })
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('event')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('event')
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->all()),
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('log_name')
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()),
            ], FiltersLayout::AboveContentCollapsible)
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            ->extremePaginationLinks()
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
            ]));
    }

    private static function formatMorph(?string $type, mixed $id): string
    {
        if (blank($type)) {
            return '—';
        }

        $base = class_basename($type);

        return filled($id) ? "{$base} #{$id}" : $base;
    }
}
