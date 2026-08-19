<?php

namespace App\Filament\App\Resources\InboundConnections\Schemas;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Models\InboundConnection;
use App\Support\Secrets\MasksIntegrationSecrets;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InboundConnectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('serialization_provider')
                            ->badge()
                            ->formatStateUsing(fn (SerializationProvider $state): string => $state->label()),
                        TextEntry::make('transport')
                            ->badge()
                            ->formatStateUsing(fn (InboundTransport $state): string => $state->label()),
                        TextEntry::make('tradingPartner.name')
                            ->label('Trading partner')
                            ->placeholder('Not linked'),
                        IconEntry::make('is_active')
                            ->boolean(),
                        TextEntry::make('last_received_at')
                            ->dateTime()
                            ->placeholder('Never'),
                        TextEntry::make('last_polled_at')
                            ->dateTime()
                            ->placeholder('Never'),
                        TextEntry::make('last_error')
                            ->placeholder('None')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Endpoints')
                    ->schema([
                        TextEntry::make('webhook_url')
                            ->label('HTTPS webhook URL')
                            ->state(fn (InboundConnection $record): ?string => $record->webhookUrl())
                            ->placeholder('Not applicable')
                            ->copyable(),
                        TextEntry::make('hub_url')
                            ->label('Centralized hub URL')
                            ->state(fn (InboundConnection $record): ?string => $record->hubUrl())
                            ->copyable()
                            ->visible(fn (InboundConnection $record): bool => $record->hubUrl() !== null),
                        TextEntry::make('hub_registered')
                            ->label('Hub routing')
                            ->state(fn (InboundConnection $record): string => $record->isHubRegistered()
                                ? 'Registered for hub routing (receiver GLN → tenant)'
                                : 'Not registered')
                            ->visible(fn (InboundConnection $record): bool => $record->hubUrl() !== null),
                        TextEntry::make('inbound_token')
                            ->label('Inbound token')
                            ->formatStateUsing(fn (?string $state): ?string => MasksIntegrationSecrets::maskToken($state)),
                    ])
                    ->columns(1),
            ]);
    }
}
