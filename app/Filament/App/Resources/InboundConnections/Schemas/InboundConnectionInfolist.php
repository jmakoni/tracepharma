<?php

namespace App\Filament\App\Resources\InboundConnections\Schemas;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Models\InboundConnection;
use App\Support\Integrations\CredentialExpiry;
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
                            ->label('Network')
                            ->badge()
                            ->formatStateUsing(fn (SerializationProvider $state): string => $state->label()),
                        TextEntry::make('transport')
                            ->badge()
                            ->formatStateUsing(fn (InboundTransport $state): string => $state->label()),
                        TextEntry::make('tradingPartner.name')
                            ->label('Partner')
                            ->placeholder('Not linked'),
                        IconEntry::make('is_active')
                            ->boolean(),
                        TextEntry::make('approval_status')
                            ->label('Platform review')
                            ->badge()
                            ->formatStateUsing(fn (ConnectionApprovalStatus $state): string => $state->label())
                            ->color(fn (ConnectionApprovalStatus $state): string => $state->color())
                            ->helperText(fn (InboundConnection $record): ?string => match (true) {
                                $record->isPendingApproval() => 'Awaiting platform approval — this connection cannot receive documents yet.',
                                $record->isRejected() => 'Rejected by platform review. Edit the connection to resubmit.',
                                $record->isSuspended() => 'Suspended by the platform — this connection cannot receive documents until resumed. Edit the connection to resubmit for review.',
                                default => null,
                            }),
                        TextEntry::make('approval_note')
                            ->label('Review note')
                            ->placeholder('—')
                            ->visible(fn (InboundConnection $record): bool => $record->isRejected() || $record->isSuspended())
                            ->columnSpanFull(),
                        TextEntry::make('credentials_expire_at')
                            ->label('Credential expiry')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => CredentialExpiry::label($state))
                            ->color(fn ($state): string => CredentialExpiry::badgeColor($state)),
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
                            ->label('Hub URL (give to your network operator)')
                            ->state(fn (InboundConnection $record): ?string => $record->hubUrl())
                            ->copyable()
                            ->visible(fn (InboundConnection $record): bool => $record->hubUrl() !== null),
                        TextEntry::make('hub_registered')
                            ->label('Hub routing')
                            ->state(fn (InboundConnection $record): string => $record->isHubRegistered()
                                ? 'Registered — files addressed to your GLN route to this connection'
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
