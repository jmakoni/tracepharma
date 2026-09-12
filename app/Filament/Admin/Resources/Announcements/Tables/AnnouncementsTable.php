<?php

namespace App\Filament\Admin\Resources\Announcements\Tables;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use App\Filament\Support\RecordActionGroup;
use App\Models\Announcement;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('severity')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (AnnouncementSeverity $state): string => ucfirst($state->value))
                    ->color(fn (AnnouncementSeverity $state): string => match ($state) {
                        AnnouncementSeverity::Critical => 'danger',
                        AnnouncementSeverity::Warning => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (AnnouncementStatus $state): string => ucfirst($state->value))
                    ->color(fn (AnnouncementStatus $state): string => match ($state) {
                        AnnouncementStatus::Published => 'success',
                        AnnouncementStatus::Retired => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('tenants_count')
                    ->label('Tenants')
                    ->counts('tenants')
                    ->sortable(),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('fan_out_succeeded_count')
                    ->label('Fan-out OK')
                    ->sortable(),
                TextColumn::make('fan_out_failed_count')
                    ->label('Fan-out failed')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions(RecordActionGroup::make([
                ViewAction::make(),
                EditAction::make(),
            ]));
    }
}
