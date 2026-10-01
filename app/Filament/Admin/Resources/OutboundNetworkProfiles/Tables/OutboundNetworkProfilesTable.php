<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OutboundNetworkProfiles\Tables;

use App\Enums\OutboundTransport;
use App\Filament\Support\RecordActionGroup;
use App\Models\OutboundNetworkProfile;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class OutboundNetworkProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Network')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('label')
                            ->options(fn (): array => DistinctColumnOptions::of(OutboundNetworkProfile::class, 'label')),
                    ),
                TextColumn::make('environment')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'prod' => 'success',
                        'stage' => 'warning',
                        default => 'gray',
                    })
                    ->columnFilter(ColumnFilter::select()->syncWith('environment')),
                TextColumn::make('default_transport')
                    ->label('Default')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => OutboundTransport::tryFrom($state)?->label() ?? $state)
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('default_transport')
                            ->options(DistinctColumnOptions::enum(OutboundTransport::class)),
                    ),
                TextColumn::make('endpoint_url')
                    ->label('HTTPS endpoint')
                    ->limit(48)
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('endpoint_url')
                            ->options(fn (): array => DistinctColumnOptions::of(OutboundNetworkProfile::class, 'endpoint_url')),
                    ),
                TextColumn::make('as2_to')
                    ->label('AS2-To')
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('as2_to')
                            ->options(fn (): array => DistinctColumnOptions::of(OutboundNetworkProfile::class, 'as2_to')),
                    ),
                IconColumn::make('is_locked')
                    ->label('Locked')
                    ->boolean()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_locked')
                            ->options(DistinctColumnOptions::boolean('Locked', 'Unlocked')),
                    ),
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
