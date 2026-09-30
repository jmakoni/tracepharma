<?php

namespace App\Filament\App\Resources\OutboundConnections\Tables;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\OutboundConformanceState;
use App\Enums\OutboundConnectionKind;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\OutboundConnection;
use App\Support\Integrations\ConnectionHealthTracker;
use App\Support\Integrations\CredentialExpiry;
use App\Support\Tables\DistinctColumnOptions;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Zvizvi\FilamentColumnFilters\Filters\ColumnFilter;

class OutboundConnectionsTable
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
                            ->options(fn (): array => DistinctColumnOptions::of(OutboundConnection::class, 'name')),
                    ),
                TextColumn::make('hub_badge')
                    ->label('Send via')
                    ->state(function (OutboundConnection $record): string {
                        $provider = $record->serialization_provider instanceof SerializationProvider
                            ? $record->serialization_provider->label()
                            : '—';
                        $transport = $record->transport instanceof OutboundTransport
                            ? $record->transport->label()
                            : '—';
                        $conformance = $record->conformanceState()->label();

                        return "{$provider} · {$transport} · {$conformance}";
                    })
                    ->badge()
                    ->color(fn (OutboundConnection $record): string => match ($record->connectionKind()) {
                        OutboundConnectionKind::ProviderHub => 'info',
                        OutboundConnectionKind::DirectPartner => 'warning',
                        OutboundConnectionKind::LocalDelivery => 'gray',
                    })
                    ->columnFilter(ColumnFilter::select()->syncWith('serialization_provider')),
                TextColumn::make('tradingPartners.name')
                    ->label('Customers')
                    ->badge()
                    ->separator(',')
                    ->placeholder('Global')
                    ->toggleable(),
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
                TextColumn::make('last_sent_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->columnFilter(ColumnFilter::date()),
            ])
            ->filters([
                SelectFilter::make('serialization_provider')
                    ->label('Network')
                    ->options(collect(SerializationProvider::cases())->mapWithKeys(
                        fn (SerializationProvider $p): array => [$p->value => $p->label()]
                    )),
                SelectFilter::make('conformance_state')
                    ->label('Status')
                    ->options(OutboundConformanceState::options()),
            ])
            ->defaultSort('name');
    }
}
