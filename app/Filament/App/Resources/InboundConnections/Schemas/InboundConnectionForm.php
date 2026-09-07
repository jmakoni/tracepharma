<?php

namespace App\Filament\App\Resources\InboundConnections\Schemas;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Models\InboundConnection;
use App\Models\TradingPartner;
use App\Rules\RejectTenantGln;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Gs1\GlnRules;
use App\Support\SftpConnectionProviderFactory;
use App\Support\TenantSettings;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class InboundConnectionForm
{
    private const SOURCE_TRACEPHARMA_HUB = 'tracepharma_hub';

    private const SOURCE_EXTERNAL_NETWORK = 'external_network';

    private const SOURCE_DIRECT = 'direct';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Source')
                        ->description('Where do your EPCIS documents come from?')
                        ->schema([
                            Callout::make('Platform review')
                                ->description(function (?InboundConnection $record): string {
                                    if ($record === null) {
                                        return 'New connections need platform approval before they can receive documents. Finish setup now — receiving unlocks once approved.';
                                    }

                                    if ($record->isPendingApproval()) {
                                        return 'Awaiting platform approval — this connection cannot receive documents yet.';
                                    }

                                    return 'Rejected by platform review: '.($record->approval_note ?? 'No reason given.').' Save the connection to resubmit.';
                                })
                                ->color(fn (?InboundConnection $record): string => $record?->isRejected() ? 'danger' : 'warning')
                                ->visible(fn (?InboundConnection $record): bool => $record === null || ! $record->isApproved())
                                ->columnSpanFull(),
                            Radio::make('source_choice')
                                ->label('Source')
                                ->options([
                                    self::SOURCE_TRACEPHARMA_HUB => 'TracePharma hub (another TracePharma tenant)',
                                    self::SOURCE_EXTERNAL_NETWORK => 'An external network (Systech, UniTrace, …)',
                                    self::SOURCE_DIRECT => 'Direct from one partner',
                                ])
                                ->descriptions([
                                    self::SOURCE_TRACEPHARMA_HUB => 'Receive documents sent by another company on this platform.',
                                    self::SOURCE_EXTERNAL_NETWORK => 'Documents arrive through a serialization network you already use.',
                                    self::SOURCE_DIRECT => 'A partner posts or drops files straight to you (HTTPS or SFTP).',
                                ])
                                ->default(self::SOURCE_EXTERNAL_NETWORK)
                                ->dehydrated(false)
                                ->live()
                                ->afterStateHydrated(function (?string $state, Get $get, Set $set): void {
                                    if ($state !== null && $state !== '') {
                                        return;
                                    }

                                    $provider = SerializationProvider::tryFrom((string) ($get('serialization_provider') ?? ''));

                                    $set('source_choice', match ($provider) {
                                        SerializationProvider::TracePharma => self::SOURCE_TRACEPHARMA_HUB,
                                        SerializationProvider::CustomHttps,
                                        SerializationProvider::CustomAs2,
                                        SerializationProvider::CustomSftp,
                                        SerializationProvider::Other => self::SOURCE_DIRECT,
                                        default => self::SOURCE_EXTERNAL_NETWORK,
                                    });
                                })
                                ->afterStateUpdated(function (?string $state, Set $set): void {
                                    if ($state === self::SOURCE_TRACEPHARMA_HUB) {
                                        $set('serialization_provider', SerializationProvider::TracePharma->value);
                                        $set('transport', InboundTransport::Https->value);

                                        return;
                                    }

                                    if ($state === self::SOURCE_DIRECT) {
                                        $set('serialization_provider', SerializationProvider::CustomHttps->value);
                                        $set('transport', InboundTransport::Https->value);

                                        return;
                                    }

                                    $set('serialization_provider', SerializationProvider::UniTrace->value);
                                    $set('transport', InboundTransport::Https->value);
                                }),
                            TextInput::make('name')
                                ->label('Connection name')
                                ->required()
                                ->maxLength(255)
                                ->helperText('Something you will recognize later, e.g. "UniTrace inbound" or "ACME direct".'),
                        ]),
                    Step::make('Network')
                        ->label(fn (Get $get): string => $get('source_choice') === self::SOURCE_DIRECT
                            ? 'Connection'
                            : 'Network & registration')
                        ->schema([
                            Select::make('serialization_provider')
                                ->label(fn (Get $get): string => $get('source_choice') === self::SOURCE_DIRECT
                                    ? 'Connection type'
                                    : 'Which network?')
                                ->options(fn (Get $get): array => $get('source_choice') === self::SOURCE_DIRECT
                                    ? [
                                        SerializationProvider::CustomHttps->value => SerializationProvider::CustomHttps->label(),
                                        SerializationProvider::CustomSftp->value => SerializationProvider::CustomSftp->label(),
                                        SerializationProvider::Other->value => SerializationProvider::Other->label(),
                                    ]
                                    : collect(SerializationProvider::networkProfileProviders())
                                        ->reject(fn (SerializationProvider $provider): bool => $provider === SerializationProvider::TracePharma)
                                        ->mapWithKeys(fn (SerializationProvider $provider): array => [$provider->value => $provider->label()])
                                        ->all())
                                ->visible(fn (Get $get): bool => $get('source_choice') !== self::SOURCE_TRACEPHARMA_HUB)
                                ->dehydratedWhenHidden()
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (?string $state, Set $set): void {
                                    if ($provider = SerializationProvider::tryFrom((string) $state)) {
                                        $set('transport', $provider->defaultTransport()->value);
                                    }
                                }),
                            Select::make('transport')
                                ->label('Transport')
                                ->options(collect(InboundTransport::operatorSelectable())->mapWithKeys(
                                    fn (InboundTransport $transport): array => [$transport->value => $transport->label()]
                                ))
                                ->required()
                                ->live(),
                            Placeholder::make('receiving_gln')
                                ->label('Your receiving GLN')
                                ->content(function (): string {
                                    $tenant = tenant();
                                    if ($tenant === null) {
                                        return '—';
                                    }

                                    $gln = TenantSettings::forTenant($tenant)->gln();

                                    return is_string($gln) && $gln !== ''
                                        ? $gln
                                        : 'Not set — ask your admin to set your organization GLN first.';
                                })
                                ->helperText('Senders address documents to this GLN (the receiver GLN in the file header).')
                                ->visible(fn (Get $get): bool => SerializationProvider::tryFrom((string) ($get('serialization_provider') ?? ''))
                                    ?->supportsHubRouting() === true),
                            Toggle::make('register_hub_routing')
                                ->label(fn (Get $get): string => self::hubToggleLabel($get))
                                ->helperText(fn (?InboundConnection $record): string => ($record !== null && ! $record->isApproved())
                                    ? 'Hub routing unlocks once the platform approves this connection.'
                                    : 'Documents addressed to your GLN arrive at the platform hub and are routed to this connection. Your platform admin must have enabled this network for your organization.')
                                ->dehydrated(false)
                                ->visible(fn (Get $get): bool => self::hubRoutingToggleVisible($get)),
                        ]),
                    Step::make('Who sends to you')
                        ->schema([
                            Select::make('trading_partner_id')
                                ->label('Partner')
                                ->relationship('tradingPartner', 'name')
                                ->searchable()
                                ->nullable()
                                ->visible(fn (Get $get): bool => ! filter_var($get('settings.multi_partner_routing') ?? false, FILTER_VALIDATE_BOOLEAN)),
                            Toggle::make('settings.multi_partner_routing')
                                ->label('More than one partner sends through this connection')
                                ->helperText('Route each file to the right partner by the sender GLN in the file header.')
                                ->live()
                                ->default(false),
                            Repeater::make('partner_routing_mappings')
                                ->label('Senders')
                                ->visible(fn (Get $get): bool => filter_var($get('settings.multi_partner_routing') ?? false, FILTER_VALIDATE_BOOLEAN))
                                ->schema([
                                    Select::make('trading_partner_id')
                                        ->label('Partner')
                                        ->options(fn (): array => TradingPartner::query()
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all())
                                        ->searchable()
                                        ->required(),
                                    // Routing compares this against the GLN in the file header, so a
                                    // value that is not a GLN can only ever match nothing.
                                    GlnRules::apply(TextInput::make('sender_gln')->label('Partner\'s GLN as sender'))
                                        ->rule(new RejectTenantGln)
                                        ->helperText('The GLN in the file header that identifies this sender.'),
                                    Toggle::make('is_default')
                                        ->label('Default partner'),
                                    TextInput::make('priority')
                                        ->label('Priority')
                                        ->numeric()
                                        ->default(0),
                                ])
                                ->columns(2)
                                ->reorderable()
                                ->collapsible()
                                ->defaultItems(0)
                                ->dehydrated(false),
                            Toggle::make('is_active')
                                ->default(true)
                                ->helperText(fn (?InboundConnection $record): ?string => $record !== null && ! $record->isApproved()
                                    ? 'Takes effect once the platform approves this connection.'
                                    : null),
                        ]),
                    Step::make('Handoff details')
                        ->description('What your network operator or partner needs to send you files.')
                        ->schema([
                            Placeholder::make('hub_handoff')
                                ->label('Give this to your network operator')
                                ->content(function (Get $get): HtmlString {
                                    $provider = SerializationProvider::tryFrom((string) ($get('serialization_provider') ?? ''));
                                    $tenant = tenant();
                                    $environment = is_string($tenant?->inbound_environment) ? $tenant->inbound_environment : '';

                                    $url = $provider !== null && $environment !== ''
                                        ? app(EpcisHubPlatformConfig::class)->hubUrl($environment, $provider->hubProviderSlug())
                                        : null;

                                    $urlLine = $url !== null
                                        ? '<code class="block rounded-md bg-gray-100 px-3 py-2 text-xs dark:bg-gray-800">'.e($url).'</code>'
                                        : '<span class="text-gray-500">Hub URL is not configured for your environment yet — ask your TracePharma admin.</span>';

                                    return new HtmlString(
                                        '<div class="space-y-3 text-sm">'
                                        .'<div><span class="font-medium">Hub URL (POST EPCIS here)</span>'.$urlLine.'</div>'
                                        .'<div><span class="font-medium">Header</span><code class="block rounded-md bg-gray-100 px-3 py-2 text-xs dark:bg-gray-800">X-Epcis-Hub-Token: &lt;platform hub token&gt;</code></div>'
                                        .'<p class="text-gray-600 dark:text-gray-400">The platform hub token authenticates the POST — ask your TracePharma admin for the current value. Documents must be addressed to your receiving GLN (previous step).</p>'
                                        .'</div>',
                                    );
                                })
                                ->visible(fn (Get $get): bool => $get('transport') === InboundTransport::Https->value
                                    && SerializationProvider::tryFrom((string) ($get('serialization_provider') ?? ''))?->supportsHubRouting() === true
                                    && filter_var($get('register_hub_routing') ?? false, FILTER_VALIDATE_BOOLEAN))
                                ->columnSpanFull(),
                            Placeholder::make('direct_https_handoff')
                                ->label('Give this to your partner')
                                ->content(function (Get $get, ?InboundConnection $record): HtmlString {
                                    if ($record === null || ! $record->exists) {
                                        return new HtmlString(
                                            '<p class="text-sm text-gray-600 dark:text-gray-400">Save this connection first — your inbound URL and token then appear on the connection page (copy icons).</p>',
                                        );
                                    }

                                    $url = $record->webhookUrl();

                                    return new HtmlString(
                                        '<div class="space-y-3 text-sm">'
                                        .'<div><span class="font-medium">Inbound URL (POST EPCIS here)</span><code class="block rounded-md bg-gray-100 px-3 py-2 text-xs dark:bg-gray-800">'.e($url ?? '—').'</code></div>'
                                        .'<div><span class="font-medium">Header</span><code class="block rounded-md bg-gray-100 px-3 py-2 text-xs dark:bg-gray-800">X-Inbound-Token: &lt;this connection\'s token&gt;</code></div>'
                                        .'<p class="text-gray-600 dark:text-gray-400">The token is shown on the connection page. Add a webhook_token credential below if this partner needs a separate secret.</p>'
                                        .'</div>',
                                    );
                                })
                                ->visible(fn (Get $get): bool => $get('transport') === InboundTransport::Https->value
                                    && ! filter_var($get('register_hub_routing') ?? false, FILTER_VALIDATE_BOOLEAN))
                                ->columnSpanFull(),
                            Section::make('HTTPS settings')
                                ->visible(fn (Get $get): bool => $get('transport') === InboundTransport::Https->value)
                                ->schema([
                                    TextInput::make('settings.inbound_path')
                                        ->label('Inbound path hint')
                                        ->placeholder('/v1/epcis/receive'),
                                    TextInput::make('settings.environment')
                                        ->placeholder('sandbox / production'),
                                ])
                                ->columns(2),
                            Section::make('Webhook credentials')
                                ->visible(fn (Get $get): bool => $get('transport') === InboundTransport::Https->value)
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
                                        ->helperText('Optional webhook_token or webhook_secret. When blank, the inbound token is used.'),
                                ]),
                            Section::make('SFTP settings')
                                ->visible(fn (Get $get): bool => $get('transport') === InboundTransport::Sftp->value)
                                ->schema([
                                    TextInput::make('settings.host')
                                        ->label('Host')
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
                                    TextInput::make('settings.inbound_path')
                                        ->label('Inbound path')
                                        ->default('/inbound/epcis')
                                        ->required(fn (Get $get): bool => $get('transport') === InboundTransport::Sftp->value),
                                    TextInput::make('settings.processed_path')
                                        ->label('Processed path')
                                        ->default('processed'),
                                    TextInput::make('settings.root')
                                        ->label('Remote root')
                                        ->default('/'),
                                ])
                                ->columns(2),
                            Section::make('SFTP credentials')
                                ->visible(fn (Get $get): bool => $get('transport') === InboundTransport::Sftp->value)
                                ->schema([
                                    TextInput::make('sftp_username')
                                        ->label('Username')
                                        ->required(fn (Get $get): bool => $get('transport') === InboundTransport::Sftp->value),
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
                            Section::make('Notes')
                                ->schema([
                                    Textarea::make('settings.notes')
                                        ->label('Internal notes')
                                        ->rows(3)
                                        ->columnSpanFull(),
                                ]),
                        ]),
                ])
                    ->skippable(fn (?InboundConnection $record): bool => $record !== null)
                    ->columnSpanFull(),
            ]);
    }

    private static function hubToggleLabel(Get $get): string
    {
        $provider = SerializationProvider::tryFrom((string) ($get('serialization_provider') ?? ''));

        return match ($provider) {
            SerializationProvider::TracePharma => 'Receive through the TracePharma hub',
            SerializationProvider::Systech, SerializationProvider::UniTrace => 'Receive through the '.$provider->label().' hub',
            default => 'Receive through the network hub',
        };
    }

    private static function hubRoutingToggleVisible(Get $get): bool
    {
        if ($get('transport') !== InboundTransport::Https->value) {
            return false;
        }

        $provider = SerializationProvider::tryFrom((string) $get('serialization_provider'));

        if ($provider?->supportsHubRouting() !== true) {
            return false;
        }

        $tenant = tenant();

        if ($tenant === null) {
            return false;
        }

        $environment = $tenant->inbound_environment;

        if (! is_string($environment) || ! in_array($environment, EpcisHubPlatformConfig::ENVIRONMENTS, true)) {
            return false;
        }

        $slug = $provider->hubProviderSlug();
        $tenantProviders = is_array($tenant->hub_providers) ? $tenant->hub_providers : [];
        $tenantProviders = array_map(
            static fn ($item) => is_string($item) ? strtolower(trim($item)) : '',
            $tenantProviders,
        );

        if (! in_array($slug, $tenantProviders, true)) {
            return false;
        }

        return in_array($slug, app(EpcisHubPlatformConfig::class)->enabledProviders($environment), true);
    }
}
