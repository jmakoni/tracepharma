<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Models\PlatformAuditEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlatformAuditEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'platformAuditEvents';

    protected static ?string $title = 'Platform audit';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('action')
                    ->badge()
                    ->searchable(),
                TextColumn::make('admin.email')
                    ->label('Admin')
                    ->placeholder('system/CLI')
                    ->toggleable(),
                TextColumn::make('target_type')
                    ->label('Target')
                    ->formatStateUsing(fn (?string $state, $record): string => $state !== null
                        ? class_basename($state).($record->target_id !== null ? ' #'.$record->target_id : '')
                        : '—')
                    ->toggleable(),
                TextColumn::make('payload')
                    ->label('Details')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? json_encode($state) : '—')
                    ->limit(80)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn (): array => PlatformAuditEvent::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all()),
            ]);
    }
}
