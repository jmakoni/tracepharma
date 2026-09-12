<?php

namespace App\Filament\App\Resources\OutboundConnections\Schemas;

use App\Enums\ConnectionApprovalStatus;
use App\Enums\OutboundConformanceState;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\OutboundConnection;
use App\Support\Integrations\CredentialExpiry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OutboundConnectionInfolist
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
                        TextEntry::make('network_profile')
                            ->label('Network profile')
                            ->state(fn (OutboundConnection $record): string => $record->networkProfile()?->displayLabel()
                                ?? 'None — own endpoint')
                            ->helperText(fn (OutboundConnection $record): ?string => $record->networkProfile() !== null
                                ? ($record->override_endpoint ? 'Using own endpoint (override on).' : 'Endpoint and AS2 details come from this profile.')
                                : null),
                        TextEntry::make('transport')
                            ->badge()
                            ->formatStateUsing(fn (OutboundTransport $state): string => $state->label()),
                        TextEntry::make('tradingPartners.name')
                            ->label('Customers')
                            ->badge()
                            ->separator(',')
                            ->placeholder('Global (any customer)'),
                        IconEntry::make('is_active')
                            ->boolean(),
                        TextEntry::make('approval_status')
                            ->label('Platform review')
                            ->badge()
                            ->formatStateUsing(fn (ConnectionApprovalStatus $state): string => $state->label())
                            ->color(fn (ConnectionApprovalStatus $state): string => $state->color())
                            ->helperText(fn (OutboundConnection $record): ?string => match (true) {
                                $record->isPendingApproval() => 'Awaiting platform approval — this connection cannot send documents yet.',
                                $record->isRejected() => 'Rejected by platform review. Edit the connection to resubmit.',
                                $record->isSuspended() => 'Suspended by the platform — this connection cannot send documents until resumed. Edit the connection to resubmit for review.',
                                default => null,
                            }),
                        TextEntry::make('approval_note')
                            ->label('Review note')
                            ->placeholder('—')
                            ->visible(fn (OutboundConnection $record): bool => $record->isRejected() || $record->isSuspended())
                            ->columnSpanFull(),
                        TextEntry::make('credentials_expire_at')
                            ->label('Credential expiry')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => CredentialExpiry::label($state))
                            ->color(fn ($state): string => CredentialExpiry::badgeColor($state))
                            ->helperText(fn (OutboundConnection $record): ?string => $record->transport === OutboundTransport::As2
                                ? 'Derived from the earliest AS2 certificate expiry.'
                                : null),
                        IconEntry::make('is_default')
                            ->label('Default for these customers')
                            ->boolean(),
                        TextEntry::make('conformance_state')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (OutboundConformanceState|string|null $state): string => match (true) {
                                $state instanceof OutboundConformanceState => $state->label(),
                                is_string($state) && $state !== '' => OutboundConformanceState::tryFrom($state)?->label() ?? $state,
                                default => OutboundConformanceState::Test->label(),
                            }),
                        TextEntry::make('settings.epcis_document_version')
                            ->label('EPCIS document version')
                            ->state(function (OutboundConnection $record): string {
                                $version = is_array($record->settings)
                                    ? (string) ($record->settings['epcis_document_version'] ?? '1.2')
                                    : '1.2';

                                return $version === '2.0' ? 'EPCIS 2.0 JSON-LD' : 'EPCIS 1.2 XML';
                            })
                            ->badge()
                            ->color(fn (OutboundConnection $record): string => (is_array($record->settings) && ($record->settings['epcis_document_version'] ?? '1.2') === '2.0')
                                ? 'info'
                                : 'gray')
                            ->helperText('Ship Orders follow this connection version when accept_20 allows 2.0; otherwise 1.2 XML.'),
                        TextEntry::make('last_sent_at')
                            ->dateTime()
                            ->placeholder('Never'),
                        TextEntry::make('last_error')
                            ->placeholder('None')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Endpoint')
                    ->schema([
                        TextEntry::make('settings.endpoint_url')
                            ->label('HTTPS endpoint URL')
                            ->state(fn (OutboundConnection $record): ?string => $record->effectiveEndpointUrl())
                            ->helperText(fn (OutboundConnection $record): ?string => $record->networkProfile() !== null && ! $record->override_endpoint
                                ? 'From the network profile.'
                                : null)
                            ->copyable()
                            ->placeholder('—')
                            ->visible(fn (OutboundConnection $record): bool => $record->transport === OutboundTransport::Https),
                        TextEntry::make('settings.host')
                            ->label('SFTP host')
                            ->copyable()
                            ->placeholder('—')
                            ->visible(fn (OutboundConnection $record): bool => $record->transport === OutboundTransport::Sftp),
                        TextEntry::make('settings.outbound_path')
                            ->label('SFTP outbound path')
                            ->copyable()
                            ->placeholder('—')
                            ->visible(fn (OutboundConnection $record): bool => $record->transport === OutboundTransport::Sftp),
                        TextEntry::make('settings.as2_url')
                            ->label('AS2 URL')
                            ->copyable()
                            ->placeholder('—')
                            ->visible(fn (OutboundConnection $record): bool => $record->transport === OutboundTransport::As2),
                        TextEntry::make('as2_smime_notice')
                            ->label('S/MIME status')
                            ->state(fn (OutboundConnection $record): string => $record->as2SmimeActive()
                                ? 'Active — lean S/MIME CMS signing/encryption applied on outbound send when certificates are configured.'
                                : 'Lab mode — raw XML over HTTPS with AS2 headers until signing or partner encryption certificates are configured.')
                            ->color(fn (OutboundConnection $record): string => $record->as2SmimeActive() ? 'success' : 'warning')
                            ->columnSpanFull()
                            ->visible(fn (OutboundConnection $record): bool => $record->transport === OutboundTransport::As2),
                        TextEntry::make('as2_cert_vault_status')
                            ->label('Certificate vault')
                            ->state(fn (OutboundConnection $record): string => $record->as2SmimeActive()
                                ? 'Active — signing and/or partner encryption certificates configured (encrypted at rest)'
                                : 'Lab mode — configure signing key pair and/or partner encryption cert to enable S/MIME')
                            ->color(fn (OutboundConnection $record): string => $record->as2SmimeActive() ? 'success' : 'warning')
                            ->visible(fn (OutboundConnection $record): bool => $record->transport === OutboundTransport::As2),
                    ])
                    ->columns(1),
            ]);
    }
}
