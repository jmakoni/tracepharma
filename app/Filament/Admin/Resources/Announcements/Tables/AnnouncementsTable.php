<?php

namespace App\Filament\Admin\Resources\Announcements\Tables;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Filament\Support\RecordActionGroup;
use App\Models\Announcement;
use App\Support\Tables\DistinctColumnOptions;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('title')
                            ->options(fn (): array => DistinctColumnOptions::of(Announcement::class, 'title')),
                    ),
                TextColumn::make('severity')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (AnnouncementSeverity $state): string => ucfirst($state->value))
                    ->color(fn (AnnouncementSeverity $state): string => match ($state) {
                        AnnouncementSeverity::Critical => 'danger',
                        AnnouncementSeverity::Warning => 'warning',
                        default => 'info',
                    })
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('severity')
                            ->options(DistinctColumnOptions::enum(AnnouncementSeverity::class)),
                    ),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (AnnouncementStatus $state): string => ucfirst($state->value))
                    ->color(fn (AnnouncementStatus $state): string => match ($state) {
                        AnnouncementStatus::Published => 'success',
                        AnnouncementStatus::Retired => 'gray',
                        default => 'warning',
                    })
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('status')
                            ->options(DistinctColumnOptions::enum(AnnouncementStatus::class)),
                    ),
                TextColumn::make('tenants_count')
                    ->label('Tenants')
                    ->counts('tenants')
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::range()
                            ->applyUsing(fn (Builder $query, array $data): Builder => DistinctColumnOptions::applyHavingRange($query, $data, 'tenants_count')),
                    ),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('fan_out_succeeded_count')
                    ->label('Fan-out OK')
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::range()
                            ->applyUsing(fn (Builder $query, array $data): Builder => DistinctColumnOptions::applyHavingRange($query, $data, 'fan_out_succeeded_count')),
                    ),
                TextColumn::make('fan_out_failed_count')
                    ->label('Fan-out failed')
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::range()
                            ->applyUsing(fn (Builder $query, array $data): Builder => DistinctColumnOptions::applyHavingRange($query, $data, 'fan_out_failed_count')),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
                EditAction::make(),
            ]));
    }
}
