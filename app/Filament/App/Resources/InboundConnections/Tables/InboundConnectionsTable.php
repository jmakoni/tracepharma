<?php

namespace App\Filament\App\Resources\InboundConnections\Tables;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Models\InboundConnection;
use App\Models\TradingPartner;
use App\Support\Integrations\ConnectionHealthTracker;
use App\Support\Integrations\CredentialExpiry;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class InboundConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('name')
                            ->options(fn (): array => DistinctColumnOptions::of(InboundConnection::class, 'name')),
                    ),
                TextColumn::make('serialization_provider')
                    ->label('Network')
                    ->badge()
                    ->formatStateUsing(fn (SerializationProvider $state): string => $state->label())
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('serialization_provider')
                            ->options(DistinctColumnOptions::enum(SerializationProvider::class)),
                    ),
                TextColumn::make('transport')
                    ->badge()
                    ->formatStateUsing(fn (InboundTransport $state): string => $state->label())
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('transport')
                            ->options(DistinctColumnOptions::enum(InboundTransport::class)),
                    ),
                TextColumn::make('tradingPartner.name')
                    ->label('Partner')
                    ->placeholder('—')
                    ->toggleable()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('trading_partner_id')
                            ->options(fn (): array => DistinctColumnOptions::related(
                                InboundConnection::class,
                                'trading_partner_id',
                                TradingPartner::class,
                            )),
                    ),
                IconColumn::make('is_active')
                    ->boolean()
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('is_active')
                            ->options(DistinctColumnOptions::boolean('Active', 'Inactive')),
                    ),
                TextColumn::make('approval_status')
                    ->label('Review')
                    ->badge()
                    ->formatStateUsing(fn (ConnectionApprovalStatus $state): string => $state->label())
                    ->color(fn (ConnectionApprovalStatus $state): string => $state === ConnectionApprovalStatus::Approved ? 'gray' : $state->color())
                    ->columnFilter(
                        ColumnFilter::select()
                            ->attribute('approval_status')
                            ->options(DistinctColumnOptions::enum(ConnectionApprovalStatus::class)),
                    ),
                TextColumn::make('credentials_expire_at')
                    ->label('Credentials')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => CredentialExpiry::label($state))
                    ->color(fn ($state): string => CredentialExpiry::badgeColor($state))
                    ->toggleable()
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('consecutive_failures')
                    ->label('Health')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? 'Healthy' : "{$state} failure(s)")
                    ->color(fn (int $state): string => $state >= ConnectionHealthTracker::ALERT_THRESHOLD ? 'danger' : ($state > 0 ? 'warning' : 'success'))
                    ->sortable()
                    ->toggleable()
                    ->columnFilter(ColumnFilter::range()),
                TextColumn::make('last_received_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->columnFilter(ColumnFilter::date()),
                TextColumn::make('last_polled_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->defaultSort('name');
    }
}
