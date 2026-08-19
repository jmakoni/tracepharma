<?php

namespace App\Filament\App\Resources\InboundConnections\Schemas;

use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Models\TradingPartner;
use App\Rules\RejectTenantGln;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Gs1\GlnRules;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class InboundConnectionForm
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
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                if ($provider = SerializationProvider::tryFrom((string) $state)) {
                                    $set('transport', $provider->defaultTransport()->value);
                                }
                            }),
                        Select::make('transport')
                            ->options(collect(InboundTransport::cases())->mapWithKeys(
                                fn (InboundTransport $transport): array => [$transport->value => $transport->label()]
                            ))
                            ->required()
                            ->live(),
                        Select::make('trading_partner_id')
                            ->label('Trading partner')
                            ->relationship('tradingPartner', 'name')
                            ->searchable()
                            ->nullable()
                            ->visible(fn (Get $get): bool => ! filter_var($get('settings.multi_partner_routing') ?? false, FILTER_VALIDATE_BOOLEAN)),
                        Toggle::make('settings.multi_partner_routing')
                            ->label('Multi-partner routing')
                            ->helperText('Route inbound files to different trading partners by sender GLN on this connection.')
                            ->live()
                            ->default(false),
                        Toggle::make('is_active')
                            ->default(true),
                        Toggle::make('register_hub_routing')
                            ->label('Register for hub routing')
                            ->helperText('Expose this tenant on the centralized Systech/UniTrace hub URL. Routes by SBDH receiver GLN matching the tenant GLN. Requires Admin to grant this provider for the tenant inbound environment.')
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => self::hubRoutingToggleVisible($get)),
                    ])
                    ->columns(2),
                Section::make('Partner routing')
                    ->visible(fn (Get $get): bool => filter_var($get('settings.multi_partner_routing') ?? false, FILTER_VALIDATE_BOOLEAN))
                    ->schema([
                        Repeater::make('partner_routing_mappings')
                            ->label('Trading partners')
                            ->schema([
                                Select::make('trading_partner_id')
                                    ->label('Trading partner')
                                    ->options(fn (): array => TradingPartner::query()
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->searchable()
                                    ->required(),
                                // Routing compares this against the SBDH sender GLN, so a
                                // value that is not a GLN can only ever match nothing.
                                GlnRules::apply(TextInput::make('sender_gln')->label('Sender GLN'))
                                    ->rule(new RejectTenantGln)
                                    ->helperText('Match SBDH / EPCIS source GLN for this partner.'),
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
                    ]),
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
                Section::make('SFTP settings')
                    ->visible(fn (Get $get): bool => $get('transport') === InboundTransport::Sftp->value)
                    ->schema([
                        TextInput::make('settings.host')
                            ->label('Host'),
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
                Section::make('Notes')
                    ->schema([
                        Textarea::make('settings.notes')
                            ->label('Internal notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
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
