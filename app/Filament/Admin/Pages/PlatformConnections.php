<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Notifications\Notification;
use App\Models\Admin;
use App\Rules\RejectTenantDomainHost;
use App\Support\Auth\Permissions;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\PlatformAs2Station;
use App\Support\Integrations\PlatformSftpConfig;
use App\Support\PlatformSettings;
use App\Support\SftpConnectionProviderFactory;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use UnitEnum;

/**
 * Platform-owned connection edges (TracePharma hub, AS2 station, SFTP drop)
 * per environment. Replaces the former EPCIS Hub settings page.
 *
 * @property-read Schema $form
 */
class PlatformConnections extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Platform connections';

    protected static ?string $title = 'Platform connections';

    protected static ?int $navigationSort = 20;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected string $view = 'filament.admin.pages.platform-connections';

    /** @var array<string, string> Newly generated hub tokens, shown once until the next save. */
    public array $revealedTokens = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::CatalogManage);
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Manage the connection edges TracePharma owns per environment: hub tokens, network egress, the AS2 station, and the SFTP drop. '
            .'CLI equivalents: hub:providers, hub:enable-provider, hub:disable-provider, hub:rotate-token, hub:routes, hub:register-route, hub:unregister-route, tenant:entitle.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runAggregationLinkFkDoctor')
                ->label('Check aggregation FK drift')
                ->icon(Heroicon::OutlinedHeart)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Check aggregation link FK drift')
                ->modalDescription('Run detect-only doctor across all tenants (no --fix). Results appear on the admin Hub health widget.')
                ->action(function (): void {
                    $exitCode = Artisan::call('tracepharma:doctor-aggregation-link-fk');

                    $output = trim(Artisan::output());

                    $notification = Notification::make()
                        ->body($output !== '' ? $output : 'Inspect complete. See Hub health on the dashboard.');

                    if ($exitCode !== 0) {
                        $notification
                            ->title('Aggregation link FK doctor found drift')
                            ->warning();
                    } else {
                        $notification
                            ->title('Aggregation link FK doctor finished')
                            ->success();
                    }

                    $notification->send();
                }),
        ];
    }

    protected function fillForm(): void
    {
        $config = app(EpcisHubPlatformConfig::class);
        $station = app(PlatformAs2Station::class);
        $sftp = app(PlatformSftpConfig::class);

        $state = [];

        foreach (EpcisHubPlatformConfig::ENVIRONMENTS as $environment) {
            $providers = $config->enabledProviders($environment);

            $state[$environment] = [
                'hub_token' => '',
                'provider_tracepharma' => in_array('tracepharma', $providers, true),
                'providers_external' => array_values(array_diff($providers, ['tracepharma'])),
                'host' => PlatformSettings::get("epcis_hub.{$environment}.host") ?? '',
                'outbound_url_systech' => $config->outboundUrl($environment, 'systech') ?? '',
                'outbound_token_systech' => '',
                'outbound_url_unitrace' => $config->outboundUrl($environment, 'unitrace') ?? '',
                'outbound_token_unitrace' => '',
                'as2_station_id' => $station->stationId($environment) ?? '',
                'as2_signing_cert_pem' => '',
                'as2_signing_key_pem' => '',
                'as2_decrypt_cert_pem' => '',
                'as2_decrypt_key_pem' => '',
                'as2_senders' => $station->senders($environment),
                'sftp_host' => $sftp->host($environment) ?? '',
                'sftp_port' => (string) $sftp->port($environment),
                'sftp_username' => $sftp->username($environment) ?? '',
                'sftp_password' => '',
                'sftp_private_key' => '',
                'sftp_passphrase' => '',
                'sftp_host_fingerprint' => $sftp->hostFingerprint($environment) ?? '',
                'sftp_inbound_path' => PlatformSettings::get("platform_sftp.{$environment}.inbound_path") ?? '',
                'sftp_processed_path' => PlatformSettings::get("platform_sftp.{$environment}.processed_path") ?? '',
                'sftp_outbound_path' => PlatformSettings::get("platform_sftp.{$environment}.outbound_path") ?? '',
            ];

            $state["{$environment}_url_systech"] = $config->hubUrl($environment, 'systech');
            $state["{$environment}_url_unitrace"] = $config->hubUrl($environment, 'unitrace');
            $state["{$environment}_url_tracepharma"] = $config->hubUrl($environment, 'tracepharma');
            $state["{$environment}_as2_url"] = $station->as2Url($environment);
        }

        $this->form->fill($state);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->operation('edit')
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Platform connections')
                    ->persistTabInQueryString('tab')
                    ->columnSpanFull()
                    ->tabs([
                        Tabs\Tab::make('Inbound hub')
                            ->icon(Heroicon::OutlinedInboxArrowDown)
                            ->schema([$this->environmentSubTabs('inboundHub', 'Inbound hub environment')]),
                        Tabs\Tab::make('Outbound to networks')
                            ->icon(Heroicon::OutlinedPaperAirplane)
                            ->schema([$this->environmentSubTabs('outboundEdge', 'Outbound environment')]),
                        Tabs\Tab::make('AS2 station')
                            ->icon(Heroicon::OutlinedShieldCheck)
                            ->schema([$this->environmentSubTabs('as2Station', 'AS2 environment')]),
                        Tabs\Tab::make('SFTP drop')
                            ->icon(Heroicon::OutlinedServerStack)
                            ->schema([$this->environmentSubTabs('sftp', 'SFTP environment')]),
                    ]),
            ]);
    }

    /**
     * Plugin-style nested sub-tabs: one per environment under each edge category.
     * The shared `env` query key keeps the selected environment when switching categories.
     */
    private function environmentSubTabs(string $category, string $label): Tabs
    {
        return Tabs::make($label)
            ->persistTabInQueryString('env')
            ->columnSpanFull()
            ->tabs(
                array_map(function (string $environment) use ($category): Tabs\Tab {
                    $configured = $this->categoryConfigured($category, $environment);

                    return Tabs\Tab::make(ucfirst($environment))
                        ->badge($configured ? 'Configured' : 'Not configured')
                        ->badgeColor($configured ? 'success' : 'gray')
                        ->schema([$this->{$category.'Section'}($environment)]);
                }, EpcisHubPlatformConfig::ENVIRONMENTS),
            );
    }

    private function categoryConfigured(string $category, string $environment): bool
    {
        return match ($category) {
            'inboundHub' => app(EpcisHubPlatformConfig::class)->hubToken($environment) !== null,
            'outboundEdge' => app(EpcisHubPlatformConfig::class)->hasOutboundEdge($environment, 'systech')
                || app(EpcisHubPlatformConfig::class)->hasOutboundEdge($environment, 'unitrace'),
            'as2Station' => app(PlatformAs2Station::class)->isConfigured($environment),
            'sftp' => app(PlatformSftpConfig::class)->isConfigured($environment),
            default => false,
        };
    }

    private function inboundHubSection(string $environment): Section
    {
        return Section::make('Inbound hub (HTTPS)')
            ->key("{$environment}_inbound_hub")
            ->description('Networks POST EPCIS documents to these URLs with the X-Epcis-Hub-Token header.')
            ->compact()
            ->columns(['md' => 2])
            ->headerActions([
                Action::make("generateToken_{$environment}")
                    ->label('Generate new token')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Generate new hub token')
                    ->modalDescription('The current token stays valid for '.EpcisHubPlatformConfig::TOKEN_ROTATION_GRACE_HOURS.' hours so partners can cut over without downtime. The new token is shown once — copy it immediately.')
                    ->action(function () use ($environment): void {
                        $token = app(EpcisHubPlatformConfig::class)->rotateHubToken($environment);

                        $this->revealedTokens[$environment] = $token;
                        $this->data["{$environment}_generated_token"] = $token;

                        Notification::make()
                            ->title(ucfirst($environment).' hub token rotated')
                            ->body('Previous token stays accepted for '.EpcisHubPlatformConfig::TOKEN_ROTATION_GRACE_HOURS.' hours.')
                            ->success()
                            ->send();
                    }),
            ])
            ->schema([
                TextInput::make("{$environment}_generated_token")
                    ->label('New hub token — shown once, copy now')
                    ->disabled()
                    ->dehydrated(false)
                    ->copyable()
                    ->visible(fn (): bool => filled($this->revealedTokens[$environment] ?? null))
                    ->columnSpanFull(),
                TextInput::make("{$environment}.hub_token")
                    ->label('Hub token')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(function () use ($environment): string {
                        $expiresAt = app(EpcisHubPlatformConfig::class)->previousHubTokenExpiresAt($environment);

                        $base = 'Leave blank to keep the existing token. Prefer Generate new token for a zero-downtime rotation.';

                        return $expiresAt !== null && $expiresAt->isFuture()
                            ? $base.' Previous token is accepted until '.$expiresAt->toDayDateTimeString().'.'
                            : $base;
                    })
                    ->columnSpanFull(),
                Fieldset::make('TracePharma hub (this platform)')
                    ->schema([
                        Checkbox::make("{$environment}.provider_tracepharma")
                            ->label('Enable TracePharma hub')
                            ->helperText('Tenant-to-tenant documents inside this platform.')
                            ->live()
                            ->afterStateUpdated(fn (Get $get, callable $set) => $this->syncHubUrlFields($environment, $get, $set)),
                        TextInput::make("{$environment}_url_tracepharma")
                            ->label('TracePharma inbound hub URL')
                            ->disabled()
                            ->dehydrated(false)
                            ->copyable()
                            ->visible(fn (Get $get): bool => (bool) $get("{$environment}.provider_tracepharma"))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                Fieldset::make('External networks')
                    ->schema([
                        CheckboxList::make("{$environment}.providers_external")
                            ->label('Enabled networks')
                            ->options([
                                'systech' => 'Systech',
                                'unitrace' => 'UniTrace',
                            ])
                            ->columns(2)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, callable $set) => $this->syncHubUrlFields($environment, $get, $set)),
                        TextInput::make("{$environment}_url_systech")
                            ->label('Systech inbound hub URL')
                            ->disabled()
                            ->dehydrated(false)
                            ->copyable()
                            ->visible(fn (Get $get): bool => in_array('systech', $get("{$environment}.providers_external") ?? [], true))
                            ->columnSpanFull(),
                        TextInput::make("{$environment}_url_unitrace")
                            ->label('UniTrace inbound hub URL')
                            ->disabled()
                            ->dehydrated(false)
                            ->copyable()
                            ->visible(fn (Get $get): bool => in_array('unitrace', $get("{$environment}.providers_external") ?? [], true))
                            ->columnSpanFull(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),
                TextInput::make("{$environment}.host")
                    ->label('Host override')
                    ->placeholder(fn (): string => app(EpcisHubPlatformConfig::class)->host($environment))
                    ->helperText('Optional. Blank uses the configured default host.')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, callable $set) => $this->syncHubUrlFields($environment, $get, $set))
                    ->rules([new RejectTenantDomainHost])
                    ->maxLength(255),
            ]);
    }

    private function outboundEdgeSection(string $environment): Section
    {
        $hasEdge = fn (): bool => app(EpcisHubPlatformConfig::class)->hasOutboundEdge($environment, 'systech')
            || app(EpcisHubPlatformConfig::class)->hasOutboundEdge($environment, 'unitrace');

        return Section::make('Outbound to network hubs')
            ->description(fn (): string => $hasEdge()
                ? 'Configured — tenant documents can egress through this platform into the networks below.'
                : 'Not configured — tenants send to networks directly or via their own connections.')
            ->compact()
            ->columns(['md' => 2])
            ->schema([
                $this->outboundEdgeFields($environment, 'systech', 'Systech'),
                $this->outboundEdgeFields($environment, 'unitrace', 'UniTrace'),
            ]);
    }

    private function outboundEdgeFields(string $environment, string $provider, string $label): Fieldset
    {
        return Fieldset::make($label)
            ->schema([
                TextInput::make("{$environment}.outbound_url_{$provider}")
                    ->label("{$label} hub URL")
                    ->url()
                    ->rule('starts_with:https://')
                    ->maxLength(255)
                    ->placeholder("https://…/api/webhooks/epcis/hub/{$provider}")
                    ->helperText('Where this platform posts tenant documents into the '.$label.' network.')
                    ->columnSpanFull(),
                TextInput::make("{$environment}.outbound_token_{$provider}")
                    ->label("{$label} edge token")
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->placeholder(fn (): string => app(EpcisHubPlatformConfig::class)->outboundToken($environment, $provider) !== null ? '••••••••••••' : '')
                    ->helperText('Write-only. Leave blank to keep the existing token. Sent as the X-Epcis-Hub-Token header.')
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    private function as2StationSection(string $environment): Section
    {
        $station = app(PlatformAs2Station::class);

        return Section::make('AS2 station')
            ->description(fn (): string => app(PlatformAs2Station::class)->isConfigured($environment)
                ? 'Configured — partners can exchange AS2 with this edge.'
                : 'Not configured — the platform AS2 edge is off for this environment.')
            ->compact()
            ->columns(['md' => 2])
            ->headerActions([
                Action::make("downloadStationCert_{$environment}")
                    ->label('Download station certificate')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(fn (): bool => app(PlatformAs2Station::class)->publicCertificatePem($environment) !== null)
                    ->action(function () use ($environment) {
                        $pem = app(PlatformAs2Station::class)->publicCertificatePem($environment);

                        return response()->streamDownload(
                            function () use ($pem): void {
                                echo $pem;
                            },
                            "tracepharma-as2-station-{$environment}.crt",
                            ['Content-Type' => 'application/x-pem-file'],
                        );
                    }),
            ])
            ->schema([
                TextInput::make("{$environment}.as2_station_id")
                    ->label('Station AS2 ID')
                    ->placeholder('TRACEPHARMA-'.strtoupper($environment))
                    ->helperText('The AS2-From/AS2-To identifier partners use for this platform. Use a distinct ID per environment.')
                    ->maxLength(128),
                TextInput::make("{$environment}_as2_url")
                    ->label('Platform AS2 URL')
                    ->disabled()
                    ->dehydrated(false)
                    ->copyable()
                    ->helperText('Share this URL and the station certificate with AS2 partners.'),
                $this->pemField($environment, 'as2_decrypt_cert_pem', 'Decryption certificate (PEM)', $station->decryptCertPem($environment) !== null),
                $this->pemField($environment, 'as2_decrypt_key_pem', 'Decryption private key (PEM)', $station->decryptKeyPem($environment) !== null),
                $this->pemField($environment, 'as2_signing_cert_pem', 'Signing certificate (PEM)', $station->signingCertPem($environment) !== null),
                $this->pemField($environment, 'as2_signing_key_pem', 'Signing private key (PEM)', $station->signingKeyPem($environment) !== null),
                Repeater::make("{$environment}.as2_senders")
                    ->label('Partner sender certificates')
                    ->helperText('Inbound AS2 messages are only accepted from these AS2 IDs, verified against the registered certificate.')
                    ->schema([
                        TextInput::make('label')
                            ->label('Label')
                            ->placeholder('Acme QA')
                            ->maxLength(120),
                        TextInput::make('as2_id')
                            ->label('Partner AS2 ID (AS2-From)')
                            ->required()
                            ->maxLength(128),
                        Textarea::make('signing_cert_pem')
                            ->label('Partner signing certificate (PEM)')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add sender')
                    ->columnSpanFull(),
            ]);
    }

    private function pemField(string $environment, string $key, string $label, bool $hasExisting): Textarea
    {
        return Textarea::make("{$environment}.{$key}")
            ->label($label)
            ->rows(4)
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->placeholder($hasExisting ? '••••••••••••' : '')
            ->helperText('Write-only. Leave blank to keep the current value.');
    }

    private function sftpSection(string $environment): Section
    {
        $sftp = app(PlatformSftpConfig::class);

        return Section::make('SFTP drop')
            ->description(fn (): string => app(PlatformSftpConfig::class)->isConfigured($environment)
                ? 'Configured — the platform polls the inbound path and routes files to tenants.'
                : 'Not configured — no platform SFTP polling for this environment.')
            ->compact()
            ->columns(['md' => 2])
            ->headerActions([
                Action::make("testSftp_{$environment}")
                    ->label('Test connectivity')
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    ->action(function () use ($environment): void {
                        $config = app(PlatformSftpConfig::class);

                        if (! $config->isConfigured($environment)) {
                            Notification::make()
                                ->title('SFTP is not configured for '.ucfirst($environment))
                                ->body('Save a host, username, host key fingerprint, and a password or private key first. The test uses saved settings.')
                                ->warning()
                                ->send();

                            return;
                        }

                        try {
                            $provider = SftpConnectionProviderFactory::forPlatformEdge($config, $environment);
                            $connection = $provider->provideConnection();
                            $connection->nlist($config->inboundPath($environment));
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('SFTP connectivity failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('SFTP connectivity OK')
                            ->body('Connected and listed '.$config->inboundPath($environment).'.')
                            ->success()
                            ->send();
                    }),
            ])
            ->schema([
                TextInput::make("{$environment}.sftp_host")
                    ->label('Host')
                    ->maxLength(255),
                TextInput::make("{$environment}.sftp_port")
                    ->label('Port')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->placeholder('22'),
                TextInput::make("{$environment}.sftp_username")
                    ->label('Username')
                    ->maxLength(255),
                TextInput::make("{$environment}.sftp_password")
                    ->label('Password')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->placeholder($sftp->password($environment) !== null ? '••••••••••••' : '')
                    ->helperText('Write-only. Leave blank to keep the current password.'),
                Textarea::make("{$environment}.sftp_private_key")
                    ->label('Private key (PEM)')
                    ->rows(4)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->placeholder($sftp->privateKey($environment) !== null ? '••••••••••••' : '')
                    ->helperText('Write-only. Used instead of the password when set.')
                    ->columnSpanFull(),
                TextInput::make("{$environment}.sftp_passphrase")
                    ->label('Key passphrase')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->placeholder($sftp->passphrase($environment) !== null ? '••••••••••••' : '')
                    ->helperText('Write-only. Leave blank to keep the current passphrase.'),
                TextInput::make("{$environment}.sftp_host_fingerprint")
                    ->label('Host key fingerprint')
                    ->helperText('Required for connect/test. Colon-hex SSH host key fingerprint (MD5 for ssh-rsa, SHA-512 otherwise).')
                    ->columnSpanFull(),
                TextInput::make("{$environment}.sftp_inbound_path")
                    ->label('Inbound path')
                    ->placeholder('/')
                    ->helperText('Where the platform polls for inbound EPCIS files. Blank uses /.'),
                TextInput::make("{$environment}.sftp_processed_path")
                    ->label('Processed path')
                    ->placeholder('processed')
                    ->helperText('Files move here after routing. Blank uses "processed".'),
                TextInput::make("{$environment}.sftp_outbound_path")
                    ->label('Outbound path')
                    ->placeholder('outbound')
                    ->helperText('Default drop path for platform SFTP sends. Blank uses "outbound".'),
            ]);
    }

    private function syncHubUrlFields(string $environment, Get $get, callable $set): void
    {
        $override = $get("{$environment}.host");
        $host = is_string($override) && trim($override) !== ''
            ? strtolower(trim($override))
            : app(EpcisHubPlatformConfig::class)->host($environment);

        $set("{$environment}_url_systech", 'https://'.$host.'/api/webhooks/epcis/hub/systech');
        $set("{$environment}_url_unitrace", 'https://'.$host.'/api/webhooks/epcis/hub/unitrace');
        $set("{$environment}_url_tracepharma", 'https://'.$host.'/api/webhooks/epcis/hub/tracepharma');
        $set("{$environment}_as2_url", 'https://'.$host.'/api/webhooks/as2/hub');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $config = app(EpcisHubPlatformConfig::class);
        $station = app(PlatformAs2Station::class);
        $sftp = app(PlatformSftpConfig::class);

        foreach (EpcisHubPlatformConfig::ENVIRONMENTS as $environment) {
            $envData = is_array($data[$environment] ?? null) ? $data[$environment] : [];

            if (filled($envData['hub_token'] ?? null)) {
                $config->setHubToken($environment, (string) $envData['hub_token']);
            }

            $providers = $envData['providers_external'] ?? [];
            $providers = is_array($providers) ? array_values($providers) : [];

            if ((bool) ($envData['provider_tracepharma'] ?? false)) {
                $providers[] = 'tracepharma';
            }

            $config->setProviders($environment, array_values(array_unique($providers)));

            $host = $envData['host'] ?? null;
            $config->setHost($environment, is_string($host) ? $host : null);

            foreach (['systech', 'unitrace'] as $provider) {
                $url = $envData["outbound_url_{$provider}"] ?? null;
                $config->setOutboundUrl($environment, $provider, is_string($url) ? $url : null);

                $token = $envData["outbound_token_{$provider}"] ?? null;

                if (filled($token)) {
                    $config->setOutboundToken($environment, $provider, (string) $token);
                }
            }

            $stationValues = [
                'station_id' => is_string($envData['as2_station_id'] ?? null) ? $envData['as2_station_id'] : null,
            ];

            foreach (['as2_signing_cert_pem' => 'signing_cert_pem', 'as2_signing_key_pem' => 'signing_key_pem', 'as2_decrypt_cert_pem' => 'decrypt_cert_pem', 'as2_decrypt_key_pem' => 'decrypt_key_pem'] as $formKey => $configKey) {
                if (filled($envData[$formKey] ?? null)) {
                    $stationValues[$configKey] = (string) $envData[$formKey];
                }
            }

            $station->save($environment, $stationValues);
            $station->setSenders(
                $environment,
                is_array($envData['as2_senders'] ?? null) ? $envData['as2_senders'] : [],
            );

            $sftpValues = [
                'host' => $this->stringOrNull($envData['sftp_host'] ?? null),
                'port' => $this->stringOrNull($envData['sftp_port'] ?? null),
                'username' => $this->stringOrNull($envData['sftp_username'] ?? null),
                'host_fingerprint' => $this->stringOrNull($envData['sftp_host_fingerprint'] ?? null),
                'inbound_path' => $this->stringOrNull($envData['sftp_inbound_path'] ?? null),
                'processed_path' => $this->stringOrNull($envData['sftp_processed_path'] ?? null),
                'outbound_path' => $this->stringOrNull($envData['sftp_outbound_path'] ?? null),
            ];

            foreach (['sftp_password' => 'password', 'sftp_private_key' => 'private_key', 'sftp_passphrase' => 'passphrase'] as $formKey => $configKey) {
                if (filled($envData[$formKey] ?? null)) {
                    $sftpValues[$configKey] = (string) $envData[$formKey];
                }
            }

            $sftp->save($environment, $sftpValues);
        }

        $this->revealedTokens = [];

        Notification::make()
            ->title('Platform connections saved')
            ->success()
            ->send();

        $this->fillForm();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public static function getDocumentation(): array|string
    {
        return 'settings.platform-connections';
    }
}
