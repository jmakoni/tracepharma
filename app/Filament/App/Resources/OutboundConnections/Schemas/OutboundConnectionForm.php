<?php

namespace App\Filament\App\Resources\OutboundConnections\Schemas;

use App\Enums\As2MdnAckMode;
use App\Enums\OutboundConformanceState;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\OutboundConnection;
use App\Support\Integrations\OutboundTransportAvailability;
use App\Support\SftpConnectionProviderFactory;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class OutboundConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Connection')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('serialization_provider')
                            ->label('Serialization provider')
                            ->options(collect(SerializationProvider::cases())->mapWithKeys(
                                fn (SerializationProvider $provider): array => [$provider->value => $provider->label()]
                            ))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                $provider = SerializationProvider::tryFrom((string) $state);

                                if ($provider === null) {
                                    return;
                                }

                                $set('transport', $provider->defaultOutboundTransport()->value);

                                if (blank($get('settings.outbound_path'))) {
                                    $set('settings.outbound_path', self::defaultOutboundPath($provider));
                                }
                            }),
                        Select::make('transport')
                            ->options(collect(OutboundTransport::cases())
                                ->filter(fn (OutboundTransport $transport): bool => OutboundTransportAvailability::isSelectable($transport))
                                ->mapWithKeys(
                                    fn (OutboundTransport $transport): array => [$transport->value => $transport->label()]
                                ))
                            ->required()
                            ->live(),
                        Select::make('trading_partner_id')
                            ->label('Trading partner')
                            ->relationship('tradingPartner', 'name')
                            ->searchable()
                            ->nullable(),
                        Toggle::make('is_active')
                            ->default(true),
                        Toggle::make('is_default')
                            ->label('Default for partner')
                            ->helperText('When set, this connection is preferred for auto-routing to the selected trading partner (or globally when no partner is linked).'),
                        Placeholder::make('conformance_state_display')
                            ->label('Conformance')
                            ->content(function (?OutboundConnection $record): string {
                                if ($record === null) {
                                    return OutboundConformanceState::Test->label().' (new connections always start in Test)';
                                }

                                return $record->conformanceState()->label();
                            })
                            ->helperText('Advance via Promote or Break-glass on the connection view — not editable here.'),
                        Select::make('settings.epcis_document_version')
                            ->label('EPCIS document version')
                            ->options([
                                '1.2' => 'EPCIS 1.2 XML (default)',
                                '2.0' => 'EPCIS 2.0 JSON-LD (opt-in when accept_20 is on)',
                            ])
                            ->default('1.2')
                            ->helperText('Ship Orders follow this connection version when accept_20 allows 2.0; otherwise 1.2 XML. XML 2.0 outbound is not offered.'),
                        Select::make('settings.epcis_document_format')
                            ->label('EPCIS 2.0 format')
                            ->options([
                                'json' => 'JSON-LD (supported)',
                            ])
                            ->default('json')
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => $get('settings.epcis_document_version') === '2.0')
                            ->helperText('Only used when EPCIS 2.0 is selected. Ship Orders and disposition documents follow the connection version above.'),
                    ])
                    ->columns(2),
                Section::make('HTTPS settings')
                    ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::Https->value)
                    ->schema([
                        TextInput::make('settings.endpoint_url')
                            ->label('Endpoint URL')
                            ->url()
                            ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::Https->value)
                            ->columnSpanFull(),
                    ]),
                Section::make('AS2 settings')
                    ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value)
                    ->schema([
                        TextInput::make('settings.as2_url')
                            ->label('AS2 URL')
                            ->url()
                            ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value)
                            ->columnSpanFull(),
                        TextInput::make('settings.as2_from')
                            ->label('AS2-From')
                            ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value),
                        TextInput::make('settings.as2_to')
                            ->label('AS2-To')
                            ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value),
                        Select::make('settings.as2_mdn_ack_mode')
                            ->label('MDN ack mode')
                            ->options(collect(As2MdnAckMode::cases())->mapWithKeys(
                                fn (As2MdnAckMode $mode): array => [$mode->value => $mode->label()]
                            ))
                            ->default(As2MdnAckMode::Sync->value)
                            ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value),
                        TextInput::make('settings.disposition_notification_to')
                            ->label('Disposition-Notification-To')
                            ->url()
                            ->helperText('Return URL for async or sync MDN receipts. Omit when MDN ack mode is No MDN.'),
                        TextInput::make('as2_mdn_webhook_secret')
                            ->label('MDN webhook secret')
                            ->password()
                            ->revealable()
                            ->helperText('Required for async MDN webhook auth (X-As2-Mdn-Secret or Authorization Bearer).'),
                    ])
                    ->columns(2),
                Section::make('AS2 certificates (vault)')
                    ->description('When signing and/or partner encryption PEMs are saved, outbound sends apply lean S/MIME CMS. Without certs, AS2 posts raw XML (lab mode).')
                    ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value)
                    ->schema([
                        Textarea::make('as2_signing_cert_pem')
                            ->label('Signing certificate (PEM)')
                            ->rows(6)
                            ->columnSpanFull()
                            ->helperText('Your public certificate for outbound signing. Stored encrypted; applied when the private key is also configured.'),
                        Textarea::make('as2_signing_key_pem')
                            ->label('Signing private key (PEM, optional)')
                            ->rows(6)
                            ->columnSpanFull()
                            ->helperText('Private key paired with the signing certificate. Stored encrypted; never written to disk during send beyond OpenSSL tmpfiles.'),
                        Textarea::make('as2_partner_encrypt_cert_pem')
                            ->label('Partner encryption certificate (PEM, optional)')
                            ->rows(6)
                            ->columnSpanFull()
                            ->helperText('Partner public certificate for payload encryption after signing. Stored encrypted.'),
                    ]),
                Section::make('SFTP settings')
                    ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::Sftp->value)
                    ->schema([
                        TextInput::make('settings.host')
                            ->label('Host')
                            ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::Sftp->value)
                            ->rules([
                                fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                    if ($value === null || $value === '') {
                                        return;
                                    }

                                    try {
                                        SftpConnectionProviderFactory::assertSafeHost((string) $value);
                                    } catch (\InvalidArgumentException $exception) {
                                        $fail($exception->getMessage());
                                    }
                                },
                            ]),
                        TextInput::make('settings.port')
                            ->numeric()
                            ->default(22),
                        TextInput::make('settings.outbound_path')
                            ->label('Outbound path')
                            ->default('/outbound/epcis')
                            ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::Sftp->value),
                        TextInput::make('settings.root')
                            ->label('Remote root')
                            ->default('/'),
                    ])
                    ->columns(2),
                Section::make('SFTP credentials')
                    ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::Sftp->value)
                    ->schema([
                        TextInput::make('sftp_username')
                            ->label('Username')
                            ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::Sftp->value),
                        TextInput::make('sftp_password')
                            ->label('Password')
                            ->password()
                            ->revealable(),
                        Textarea::make('sftp_private_key')
                            ->label('Private key (PEM)')
                            ->rows(6)
                            ->columnSpanFull(),
                        TextInput::make('sftp_passphrase')
                            ->label('Private key passphrase')
                            ->password()
                            ->revealable(),
                    ])
                    ->columns(2),
                Section::make('HTTPS credentials')
                    ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::Https->value)
                    ->schema([
                        Repeater::make('credential_pairs')
                            ->label('Credentials')
                            ->schema([
                                TextInput::make('key')
                                    ->label('Key')
                                    ->required(),
                                TextInput::make('value')
                                    ->label('Value')
                                    ->password()
                                    ->revealable()
                                    ->required(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->dehydrated(false)
                            ->helperText('Optional webhook_token sent as X-Inbound-Token on POST.'),
                    ]),
                Section::make('Notes')
                    ->schema([
                        Textarea::make('settings.notes')
                            ->label('Internal notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Sensible outbound_path default per serialization provider for partner drop folders.
     */
    private static function defaultOutboundPath(SerializationProvider $provider): string
    {
        return match ($provider) {
            SerializationProvider::Systech => '/outbound/epcis/systech',
            SerializationProvider::SapIch => '/outbound/epcis/sap-ich',
            SerializationProvider::TraceLink => '/outbound/epcis/tracelink',
            SerializationProvider::Lspedia => '/outbound/epcis/lspedia',
            SerializationProvider::Advasur => '/outbound/epcis/advasur',
            SerializationProvider::Axway => '/outbound/epcis/axway',
            SerializationProvider::Rfxcel => '/outbound/epcis/rfxcel',
            SerializationProvider::UniTrace => '/outbound/epcis/unitrace',
            SerializationProvider::CustomSftp, SerializationProvider::CustomHttps, SerializationProvider::Other => '/outbound/epcis',
        };
    }
}
