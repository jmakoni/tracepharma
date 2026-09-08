<?php

namespace App\Filament\App\Resources\OutboundConnections\Schemas;

use App\Enums\As2MdnAckMode;
use App\Enums\OutboundConformanceState;
use App\Enums\OutboundConnectionKind;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\OutboundConnection;
use App\Models\OutboundNetworkProfile;
use App\Support\Integrations\OutboundSendPreset;
use App\Support\Integrations\OutboundTransportAvailability;
use App\Support\SftpConnectionProviderFactory;
use Carbon\Carbon;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class OutboundConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Destination')
                        ->description('Where should your EPCIS documents go?')
                        ->schema([
                            Callout::make('Platform review')
                                ->description(function (?OutboundConnection $record): string {
                                    if ($record === null) {
                                        return 'New connections need platform approval before they can send documents. Finish setup now — sending unlocks once approved.';
                                    }

                                    if ($record->isPendingApproval()) {
                                        return 'Awaiting platform approval — this connection cannot send documents yet.';
                                    }

                                    return 'Rejected by platform review: '.($record->approval_note ?? 'No reason given.').' Save the connection to resubmit.';
                                })
                                ->color(fn (?OutboundConnection $record): string => $record?->isRejected() ? 'danger' : 'warning')
                                ->visible(fn (?OutboundConnection $record): bool => $record === null || ! $record->isApproved())
                                ->columnSpanFull(),
                            Radio::make('settings.kind')
                                ->label('Send method')
                                ->options(OutboundConnectionKind::options())
                                ->descriptions([
                                    OutboundConnectionKind::ProviderHub->value => 'One connection into a network like LSPediA, TraceLink, or UniTrace — every customer you have on that network receives through it.',
                                    OutboundConnectionKind::DirectPartner->value => 'Straight to one customer\'s own endpoint (HTTPS, AS2, or SFTP).',
                                    OutboundConnectionKind::LocalDelivery->value => 'Customers download from your portal or receive the file by email.',
                                ])
                                ->default(OutboundConnectionKind::ProviderHub->value)
                                ->live()
                                ->required()
                                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                    $kind = OutboundConnectionKind::tryFrom((string) $state)
                                        ?? OutboundConnectionKind::ProviderHub;

                                    if ($kind === OutboundConnectionKind::LocalDelivery) {
                                        $set('serialization_provider', SerializationProvider::Other->value);
                                        $set('transport', OutboundTransport::Portal->value);
                                        $set('network_profile_id', null);
                                        $set('override_endpoint', false);

                                        return;
                                    }

                                    if ($kind === OutboundConnectionKind::DirectPartner) {
                                        $set('serialization_provider', SerializationProvider::CustomHttps->value);
                                        $set('transport', OutboundTransport::Https->value);
                                        $set('network_profile_id', null);
                                        $set('override_endpoint', false);

                                        return;
                                    }

                                    $set('serialization_provider', SerializationProvider::Lspedia->value);
                                    $set('transport', OutboundTransport::Https->value);
                                }),
                            TextInput::make('name')
                                ->label('Connection name')
                                ->required()
                                ->maxLength(255)
                                ->helperText('Something you will recognize later, e.g. "LSPediA — customers" or "Pharmacy direct AS2".'),
                        ]),
                    Step::make('Connection')
                        ->label(fn (Get $get): string => match (self::kind($get)) {
                            OutboundConnectionKind::DirectPartner => 'Customer endpoint',
                            OutboundConnectionKind::LocalDelivery => 'Delivery method',
                            OutboundConnectionKind::ProviderHub => 'Network',
                        })
                        ->schema([
                            Select::make('network_profile_id')
                                ->label('Which network?')
                                ->options(fn (): array => self::groupedNetworkProfileOptions())
                                ->helperText('One connection per network. The shared URL and AS2 details come from the profile unless you use your own endpoint.')
                                ->visible(fn (Get $get): bool => self::kind($get) === OutboundConnectionKind::ProviderHub)
                                ->live()
                                ->searchable()
                                ->nullable()
                                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                    if (! is_numeric($state)) {
                                        $set('override_endpoint', false);

                                        return;
                                    }

                                    $profile = OutboundNetworkProfile::query()->find((int) $state);
                                    if ($profile === null) {
                                        return;
                                    }

                                    $provider = SerializationProvider::tryFrom((string) $profile->network_slug);
                                    if ($provider !== null) {
                                        $set('serialization_provider', $provider->value);
                                    }

                                    $set('transport', $profile->default_transport);
                                }),
                            Placeholder::make('network_profile_summary')
                                ->label('Network profile')
                                ->content(function (Get $get): string {
                                    $id = $get('network_profile_id');
                                    if (! is_numeric($id)) {
                                        return '—';
                                    }

                                    $profile = OutboundNetworkProfile::query()->find((int) $id);
                                    if ($profile === null) {
                                        return '—';
                                    }

                                    $parts = [];
                                    if (filled($profile->endpoint_url)) {
                                        $parts[] = $profile->endpoint_url;
                                    }
                                    if (filled($profile->as2_to)) {
                                        $parts[] = 'AS2-To '.$profile->as2_to;
                                    }
                                    if (filled($profile->as2_url)) {
                                        $parts[] = 'AS2 URL '.$profile->as2_url;
                                    }

                                    return $parts !== [] ? implode(' · ', $parts) : 'Profile has no shared endpoint values yet.';
                                })
                                ->helperText('Managed by platform admins. Toggle "Use my own endpoint" to send elsewhere.')
                                ->visible(fn (Get $get): bool => self::kind($get) === OutboundConnectionKind::ProviderHub
                                    && filled($get('network_profile_id'))
                                    && ! (bool) $get('override_endpoint'))
                                ->columnSpanFull(),
                            Toggle::make('override_endpoint')
                                ->label('Use my own endpoint')
                                ->helperText('Off: send to the network profile address managed by platform admins. On: use your own URL / AS2 details below.')
                                ->visible(fn (Get $get): bool => self::kind($get) === OutboundConnectionKind::ProviderHub
                                    && filled($get('network_profile_id')))
                                ->live()
                                ->dehydrated(),
                            Select::make('serialization_provider')
                                ->label(fn (Get $get): string => self::kind($get) === OutboundConnectionKind::DirectPartner
                                    ? 'Endpoint type'
                                    : 'Network')
                                ->options(fn (Get $get): array => self::providerOptionsForKind(self::kind($get)))
                                ->visible(fn (Get $get): bool => match (self::kind($get)) {
                                    OutboundConnectionKind::DirectPartner => true,
                                    OutboundConnectionKind::ProviderHub => blank($get('network_profile_id')),
                                    OutboundConnectionKind::LocalDelivery => false,
                                })
                                ->dehydratedWhenHidden()
                                ->helperText(fn (Get $get): ?string => self::kind($get) === OutboundConnectionKind::ProviderHub
                                    ? 'Not listed above? Choose the network here.'
                                    : null)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                    $provider = SerializationProvider::tryFrom((string) $state);
                                    $kind = self::kind($get);

                                    if ($provider === null) {
                                        return;
                                    }

                                    $allowed = OutboundSendPreset::allowedTransportsFor($kind, $provider);
                                    $current = OutboundTransport::tryFrom((string) ($get('transport') ?? ''));
                                    if ($current === null || ! in_array($current, $allowed, true)) {
                                        $set('transport', OutboundSendPreset::defaultTransportFor($kind, $provider)->value);
                                    }

                                    if (blank($get('settings.outbound_path'))) {
                                        $set('settings.outbound_path', self::defaultOutboundPath($provider));
                                    }
                                }),
                            Select::make('transport')
                                ->label('Transport')
                                ->options(fn (Get $get): array => collect(
                                    OutboundSendPreset::allowedTransportsFor(
                                        self::kind($get),
                                        SerializationProvider::tryFrom((string) ($get('serialization_provider') ?? '')),
                                    ),
                                )
                                    ->filter(fn (OutboundTransport $transport): bool => OutboundTransportAvailability::isSelectable($transport))
                                    ->mapWithKeys(
                                        fn (OutboundTransport $transport): array => [$transport->value => $transport->label()]
                                    )
                                    ->all())
                                ->helperText(fn (Get $get): ?string => ($get('transport') === OutboundTransport::As2->value)
                                    ? 'AS2 needs certificates — ask IT if you have not done this before.'
                                    : null)
                                ->required()
                                ->live()
                                ->dehydrated(),
                            TextInput::make('settings.endpoint_url')
                                ->label('Endpoint URL')
                                ->url()
                                ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::Https->value
                                    && (blank($get('network_profile_id')) || (bool) $get('override_endpoint')))
                                ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::Https->value
                                    && (blank($get('network_profile_id')) || (bool) $get('override_endpoint')))
                                ->columnSpanFull(),
                            Section::make('AS2 settings')
                                ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value)
                                ->schema([
                                    TextInput::make('settings.as2_url')
                                        ->label('AS2 URL')
                                        ->url()
                                        ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value
                                            && (blank($get('network_profile_id')) || (bool) $get('override_endpoint')))
                                        ->visible(fn (Get $get): bool => blank($get('network_profile_id'))
                                            || (bool) $get('override_endpoint'))
                                        ->columnSpanFull(),
                                    TextInput::make('settings.as2_from')
                                        ->label('AS2-From')
                                        ->helperText('Your organization\'s AS2 identifier, agreed with the network or partner.')
                                        ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value),
                                    TextInput::make('settings.as2_to')
                                        ->label('AS2-To')
                                        ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value
                                            && (blank($get('network_profile_id')) || (bool) $get('override_endpoint')))
                                        ->visible(fn (Get $get): bool => blank($get('network_profile_id'))
                                            || (bool) $get('override_endpoint')),
                                    TextInput::make('settings.as2_subject')
                                        ->label('AS2 Subject (optional)')
                                        ->visible(fn (Get $get): bool => blank($get('network_profile_id'))
                                            || (bool) $get('override_endpoint')),
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
                        ]),
                    Step::make('Customers')
                        ->description('Who receives documents through this connection?')
                        ->schema([
                            Select::make('tradingPartners')
                                ->label('Customers')
                                ->relationship('tradingPartners', 'name')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->helperText(fn (Get $get): string => match (self::kind($get)) {
                                    OutboundConnectionKind::ProviderHub => 'Required. Assign every customer that should receive through this network.',
                                    OutboundConnectionKind::DirectPartner => 'Required. Exactly one customer for a direct endpoint.',
                                    OutboundConnectionKind::LocalDelivery => 'Optional. Leave empty for a global portal/email template.',
                                })
                                ->required(fn (Get $get): bool => in_array(self::kind($get), [
                                    OutboundConnectionKind::ProviderHub,
                                    OutboundConnectionKind::DirectPartner,
                                ], true)),
                            Toggle::make('is_active')
                                ->default(true)
                                ->helperText(fn (?OutboundConnection $record): ?string => $record !== null && ! $record->isApproved()
                                    ? 'Takes effect once the platform approves this connection.'
                                    : null),
                            Toggle::make('is_default')
                                ->label('Default for these customers')
                                ->helperText('Preferred route for the assigned customers (or global when none are assigned). Email is never picked by the B2B→Portal ladder.'),
                            Placeholder::make('conformance_state_display')
                                ->label('Status')
                                ->content(function (?OutboundConnection $record): string {
                                    if ($record === null) {
                                        return OutboundConformanceState::Test->label().' — new connections always start in Test';
                                    }

                                    return $record->conformanceState()->label();
                                })
                                ->helperText('Promote to Live from the connection page after a successful test send.'),
                        ]),
                    Step::make('Credentials')
                        ->label(fn (Get $get): string => self::kind($get) === OutboundConnectionKind::LocalDelivery
                            ? 'Delivery settings'
                            : 'Credentials')
                        ->schema([
                            self::httpsCredentialsSection(),
                            self::sftpSettingsSection(),
                            self::sftpCredentialsSection(),
                            self::as2CertificatesSection(),
                            self::emailSettingsSection(),
                            self::portalSettingsSection(),
                            Section::make('Advanced')
                                ->collapsed()
                                ->schema([
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
                            self::notesSection(),
                        ]),
                ])
                    ->visible(fn (?OutboundConnection $record): bool => ! ($record?->isSystemTemplate() ?? false))
                    ->skippable(fn (?OutboundConnection $record): bool => $record !== null)
                    ->columnSpanFull(),
                Group::make([
                    Section::make('System template')
                        ->description('This is a built-in Email / Client portal template. Enable it and configure settings; it cannot be deleted or have its transport changed.')
                        ->schema([
                            TextInput::make('name')
                                ->label('Connection name')
                                ->required()
                                ->maxLength(255)
                                ->disabled(),
                            Select::make('transport')
                                ->label('Transport')
                                ->options([
                                    OutboundTransport::Email->value => OutboundTransport::Email->label(),
                                    OutboundTransport::Portal->value => OutboundTransport::Portal->label(),
                                ])
                                ->required()
                                ->disabled()
                                ->dehydrated(),
                            Toggle::make('is_active'),
                            Toggle::make('is_default')
                                ->label('Default for these customers'),
                        ])
                        ->columns(2),
                    self::emailSettingsSection(),
                    self::portalSettingsSection(),
                    self::notesSection(),
                ])
                    ->visible(fn (?OutboundConnection $record): bool => $record?->isSystemTemplate() ?? false),
            ]);
    }

    private static function kind(Get $get): OutboundConnectionKind
    {
        return OutboundConnectionKind::tryFrom((string) ($get('settings.kind') ?? ''))
            ?? OutboundConnectionKind::ProviderHub;
    }

    private static function httpsCredentialsSection(): Section
    {
        return Section::make('HTTPS credentials')
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
                    ->helperText('Optional. If the receiver gave you an access token, add it as webhook_token — it is sent as a header with each POST.'),
            ]);
    }

    private static function sftpSettingsSection(): Section
    {
        return Section::make('SFTP settings')
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
                TextInput::make('settings.host_fingerprint')
                    ->label('Host key fingerprint')
                    ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::Sftp->value)
                    ->helperText('Required. Colon-hex SSH host key fingerprint (MD5 for ssh-rsa, SHA-512 otherwise). Prevents MITM.')
                    ->columnSpanFull(),
                TextInput::make('settings.outbound_path')
                    ->label('Outbound path')
                    ->default('/outbound/epcis')
                    ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::Sftp->value),
                TextInput::make('settings.root')
                    ->label('Remote root')
                    ->default('/'),
            ])
            ->columns(2);
    }

    private static function sftpCredentialsSection(): Section
    {
        return Section::make('SFTP credentials')
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
            ->columns(2);
    }

    private static function as2CertificatesSection(): Section
    {
        return Section::make('AS2 certificates (Advanced)')
            ->collapsed()
            ->description('When signing and/or partner encryption PEMs are saved, outbound sends apply lean S/MIME CMS. Without certs, AS2 posts raw XML (lab mode).')
            ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::As2->value)
            ->schema([
                Textarea::make('as2_signing_cert_pem')
                    ->label('Signing certificate (PEM)')
                    ->rows(6)
                    ->columnSpanFull()
                    ->helperText(fn (Get $get): string => self::certExpiryHelper(
                        $get('as2_signing_cert_pem'),
                        'Your public certificate for outbound signing. Stored encrypted; applied when the private key is also configured.',
                    )),
                Textarea::make('as2_signing_key_pem')
                    ->label('Signing private key (PEM, optional)')
                    ->rows(6)
                    ->columnSpanFull()
                    ->helperText('Private key paired with the signing certificate. Stored encrypted; never written to disk during send beyond OpenSSL tmpfiles.'),
                Textarea::make('as2_partner_encrypt_cert_pem')
                    ->label('Partner encryption certificate (PEM, optional)')
                    ->rows(6)
                    ->columnSpanFull()
                    ->helperText(fn (Get $get): string => self::certExpiryHelper(
                        $get('as2_partner_encrypt_cert_pem'),
                        'Partner public certificate for payload encryption after signing. Stored encrypted.',
                    )),
            ]);
    }

    private static function certExpiryHelper(mixed $pem, string $base): string
    {
        if (! is_string($pem) || trim($pem) === '') {
            return $base;
        }

        $parsed = @openssl_x509_parse($pem);
        $validTo = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

        if (! is_numeric($validTo)) {
            return $base.' Could not parse certificate expiry.';
        }

        $expires = Carbon::createFromTimestampUTC((int) $validTo);

        return $base.sprintf(
            ' Certificate %s %s.',
            $expires->isPast() ? 'expired' : 'expires',
            $expires->toDateString(),
        );
    }

    private static function emailSettingsSection(): Section
    {
        return Section::make('Email settings')
            ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::Email->value)
            ->schema([
                Repeater::make('settings.to_emails')
                    ->label('To recipients')
                    ->simple(
                        TextInput::make('email')
                            ->email()
                            ->required(),
                    )
                    ->defaultItems(1)
                    ->required(fn (Get $get): bool => $get('transport') === OutboundTransport::Email->value && ($get('is_active') ?? false))
                    ->helperText('Required when this connection is active. EPCIS XML/JSON is attached to the message.')
                    ->columnSpanFull(),
                Repeater::make('settings.cc_emails')
                    ->label('CC recipients')
                    ->simple(
                        TextInput::make('email')
                            ->email(),
                    )
                    ->defaultItems(0)
                    ->columnSpanFull(),
                TextInput::make('settings.from_name')
                    ->label('From display name')
                    ->maxLength(255),
                TextInput::make('settings.subject_template')
                    ->label('Subject template')
                    ->helperText('Optional. Tokens: {asn}, {po}, {filename}')
                    ->maxLength(255),
                TextInput::make('settings.max_attachment_mb')
                    ->label('Max attachment (MB)')
                    ->numeric()
                    ->default(15)
                    ->minValue(1)
                    ->maxValue(50)
                    ->helperText('Larger files fail with guidance to use Client portal or SFTP.'),
            ])
            ->columns(2);
    }

    private static function portalSettingsSection(): Section
    {
        return Section::make('Client portal settings')
            ->visible(fn (Get $get): bool => $get('transport') === OutboundTransport::Portal->value)
            ->schema([
                Toggle::make('settings.notify_on_publish')
                    ->label('Email notify on publish')
                    ->default(true)
                    ->helperText('Sends a login link (no attachment) when EPCIS is published to the client portal.'),
                Repeater::make('settings.invite_emails')
                    ->label('Notify / invite emails')
                    ->simple(
                        TextInput::make('email')
                            ->email(),
                    )
                    ->defaultItems(0)
                    ->columnSpanFull(),
            ]);
    }

    private static function notesSection(): Section
    {
        return Section::make('Notes')
            ->schema([
                Textarea::make('settings.notes')
                    ->label('Internal notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function providerOptionsForKind(OutboundConnectionKind $kind): array
    {
        if ($kind === OutboundConnectionKind::LocalDelivery) {
            return [SerializationProvider::Other->value => SerializationProvider::Other->label()];
        }

        if ($kind === OutboundConnectionKind::DirectPartner) {
            return [
                SerializationProvider::CustomHttps->value => SerializationProvider::CustomHttps->label(),
                SerializationProvider::CustomAs2->value => SerializationProvider::CustomAs2->label(),
                SerializationProvider::CustomSftp->value => SerializationProvider::CustomSftp->label(),
                SerializationProvider::Other->value => SerializationProvider::Other->label(),
            ];
        }

        return collect(SerializationProvider::networkProfileProviders())
            ->mapWithKeys(fn (SerializationProvider $p): array => [$p->value => $p->label()])
            ->all();
    }

    /**
     * Network profiles grouped for the "Which network?" select — platform hub first.
     *
     * @return array<string, array<int, string>>
     */
    public static function groupedNetworkProfileOptions(): array
    {
        $profiles = OutboundNetworkProfile::query()
            ->orderBy('label')
            ->orderBy('environment')
            ->get();

        $options = fn (Collection $items): array => $items
            ->mapWithKeys(fn (OutboundNetworkProfile $profile): array => [
                $profile->id => $profile->label.' — '.ucfirst($profile->environment),
            ])
            ->all();

        $groups = [];

        $platform = $profiles->filter(fn (OutboundNetworkProfile $profile): bool => $profile->isPlatformHub());
        if ($platform->isNotEmpty()) {
            $groups['TracePharma hub (this platform)'] = $options($platform);
        }

        $external = $profiles->reject(fn (OutboundNetworkProfile $profile): bool => $profile->isPlatformHub());
        if ($external->isNotEmpty()) {
            $groups['External networks'] = $options($external);
        }

        return $groups;
    }

    /**
     * Sensible outbound_path default per network for partner drop folders.
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
            SerializationProvider::TracePharma => '/outbound/epcis/tracepharma',
            SerializationProvider::CustomAs2 => '/outbound/epcis/custom-as2',
            SerializationProvider::GatewayChecker => '/outbound/epcis/gateway-checker',
            SerializationProvider::Jennason => '/outbound/epcis/jennason',
            SerializationProvider::InfiniTrak => '/outbound/epcis/infinitrak',
            SerializationProvider::TheSystemsHouse => '/outbound/epcis/the-systems-house',
            SerializationProvider::TrackTraceRx => '/outbound/epcis/tracktracerx',
            SerializationProvider::CustomSftp, SerializationProvider::CustomHttps, SerializationProvider::Other => '/outbound/epcis',
        };
    }
}
