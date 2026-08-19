<?php

namespace App\Filament\App\Resources\OutboundConnections\Tables;

use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OutboundConnectionsTable
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
                    ->formatStateUsing(fn (OutboundTransport $state): string => $state->label()),
                TextColumn::make('tradingPartner.name')
                    ->label('Trading partner')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('last_sent_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name');
    }
}
