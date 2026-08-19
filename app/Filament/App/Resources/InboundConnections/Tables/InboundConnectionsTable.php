<?php

namespace App\Filament\App\Resources\InboundConnections\Tables;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InboundConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('serialization_provider')
                    ->badge()
                    ->formatStateUsing(fn (SerializationProvider $state): string => $state->label()),
                TextColumn::make('transport')
                    ->badge()
                    ->formatStateUsing(fn (InboundTransport $state): string => $state->label()),
                TextColumn::make('tradingPartner.name')
                    ->label('Trading partner')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('last_received_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_polled_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name');
    }
}
