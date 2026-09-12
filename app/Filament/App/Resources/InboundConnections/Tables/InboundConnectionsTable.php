<?php

namespace App\Filament\App\Resources\InboundConnections\Tables;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Support\Integrations\ConnectionHealthTracker;
use App\Support\Integrations\CredentialExpiry;
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
                    ->label('Network')
                    ->badge()
                    ->formatStateUsing(fn (SerializationProvider $state): string => $state->label()),
                TextColumn::make('transport')
                    ->badge()
                    ->formatStateUsing(fn (InboundTransport $state): string => $state->label()),
                TextColumn::make('tradingPartner.name')
                    ->label('Partner')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('approval_status')
                    ->label('Review')
                    ->badge()
                    ->formatStateUsing(fn (ConnectionApprovalStatus $state): string => $state->label())
                    ->color(fn (ConnectionApprovalStatus $state): string => $state === ConnectionApprovalStatus::Approved ? 'gray' : $state->color()),
                TextColumn::make('credentials_expire_at')
                    ->label('Credentials')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => CredentialExpiry::label($state))
                    ->color(fn ($state): string => CredentialExpiry::badgeColor($state))
                    ->toggleable(),
                TextColumn::make('consecutive_failures')
                    ->label('Health')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Healthy' : "{$state} failure(s)")
                    ->color(fn (int $state): string => $state >= ConnectionHealthTracker::ALERT_THRESHOLD ? 'danger' : ($state > 0 ? 'warning' : 'success'))
                    ->sortable()
                    ->toggleable(),
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
