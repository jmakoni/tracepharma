<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OutboundNetworkProfiles\Tables;

use App\Enums\OutboundTransport;
use App\Filament\Support\RecordActionGroup;
use App\Models\OutboundNetworkProfile;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OutboundNetworkProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Network')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('environment')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'prod' => 'success',
                        'stage' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('default_transport')
                    ->label('Default')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OutboundTransport::tryFrom($state)?->label() ?? $state),
                TextColumn::make('endpoint_url')
                    ->label('HTTPS endpoint')
                    ->limit(48)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('as2_to')
                    ->label('AS2-To')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_locked')
                    ->label('Locked')
                    ->boolean(),
            ])
            ->defaultSort('label')
            ->filters([
                SelectFilter::make('environment')
                    ->options([
                        'demo' => 'Demo',
                        'test' => 'Test',
                        'stage' => 'Stage',
                        'prod' => 'Prod',
                    ]),
                SelectFilter::make('network_slug')
                    ->label('Network')
                    ->options(fn (): array => OutboundNetworkProfile::query()
                        ->orderBy('label')
                        ->pluck('label', 'network_slug')
                        ->all()),
            ])
            ->recordActions(RecordActionGroup::make([
                EditAction::make(),
            ]));
    }
}
