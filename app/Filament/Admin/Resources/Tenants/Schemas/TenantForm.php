<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Actions\Tenants\ProvisionTenantOnEnvironment;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Exceptions\OrganizationIdentityConflictException;
use App\Models\Tenant;
use App\Support\Auth\OidcProvider;
use App\Support\Auth\Permissions;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Gs1\AssertOrganizationSsccIdentity;
use App\Support\Gs1\GlnRules;
use App\Support\Places\UsState;
use App\Support\TenantHostname;
use App\Support\TenantPairAvailability;
use App\Support\TenantSettings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->compact()
                    ->columns(['md' => 2, 'lg' => 3])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('profile')
                            ->options(collect(TenantProfile::cases())->mapWithKeys(
                                fn (TenantProfile $profile) => [$profile->value => $profile->label()]
                            ))
                            ->required()
                            ->native(false),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'suspended' => 'Suspended',
                            ])
                            ->default('active')
                            ->required()
                            ->native(false),
                        GlnRules::input()
                            ->nullable()
                            ->rule(fn (Get $get, ?Tenant $record): \Closure => self::identityConflictRule($get, $record, 'gln')),
                        TextInput::make('company_prefix')
                            ->label('Company prefix (GCP)')
                            ->maxLength(11)
                            ->nullable()
                            ->rules(['nullable', 'regex:/^\d{6,11}$/'])
                            ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                try {
                                    TenantSettings::assertValidCompanyPrefix(
                                        is_string($value) ? $value : null,
                                        is_string($get('gln')) ? $get('gln') : null,
                                    );
                                } catch (\InvalidArgumentException $e) {
                                    $fail($e->getMessage());
                                }
                            })
                            ->rule(fn (Get $get, ?Tenant $record): \Closure => self::identityConflictRule($get, $record, 'company_prefix'))
                            ->helperText('6–11 digit GS1 Company Prefix used to build SGLNs from the company GLN.'),
                        Select::make('receiving_state')
                            ->label('Preferred receiving state')
                            ->options(UsState::selectOptions())
                            ->searchable()
                            ->nullable()
                            ->native(false)
                            ->helperText('Optional badge label / empty-footprint fallback. Partner ATP uses organization facility jurisdictions.'),
                        TextInput::make('tenant_slug')
                            ->label('Tenant slug')
                            ->helperText('Creates both stage and prod hosts: '.TenantHostname::pairHint())
                            ->hint(TenantHostname::pairHint())
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->visibleOn('create')
                            ->maxLength(63)
                            ->regex(TenantHostname::dnsSlugPattern())
                            ->rules([
                                fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                    $slug = strtolower((string) $value);
                                    $resumeTenantId = null;
                                    $existingProd = app(ProvisionTenantOnEnvironment::class)
                                        ->findBySlugAndEnvironment($slug, 'prod');

                                    if (
                                        $existingProd instanceof Tenant
                                        && TenantPairAvailability::ownsSlug($existingProd, $slug)
                                    ) {
                                        $resumeTenantId = (string) $existingProd->id;
                                    }

                                    $error = TenantPairAvailability::validationMessage($slug, $resumeTenantId);

                                    if ($error !== null) {
                                        $fail($error);
                                    }
                                },
                            ])
                            ->columnSpanFull(),
                    ]),
                Section::make('Initial owner')
                    ->compact()
                    ->columns(['md' => 2, 'lg' => 3])
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('owner_name')
                            ->label('Owner name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('owner_email')
                            ->label('Owner email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('owner_password')
                            ->label('Owner password')
                            ->password()
                            ->required()
                            ->minLength(8)
                            ->revealable(),
                    ]),
                Section::make('EPCIS hub')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        Select::make('inbound_environment')
                            ->label('Inbound environment')
                            ->options([
                                'demo' => 'Demo',
                                'stage' => 'Stage',
                                'prod' => 'Prod',
                            ])
                            ->native(false)
                            ->nullable()
                            ->live()
                            ->visibleOn('edit')
                            ->helperText('Which hub edge may route inbound EPCIS to this tenant. Set automatically on create (stage vs prod host).'),
                        CheckboxList::make('hub_providers')
                            ->label('Hub providers')
                            ->options(function (Get $get): array {
                                $environment = $get('inbound_environment');
                                $config = app(EpcisHubPlatformConfig::class);
                                $enabled = is_string($environment) && in_array($environment, EpcisHubPlatformConfig::ENVIRONMENTS, true)
                                    ? $config->enabledProviders($environment)
                                    : ['systech', 'unitrace'];

                                if ($enabled === []) {
                                    $enabled = ['systech', 'unitrace'];
                                }

                                return collect($enabled)
                                    ->mapWithKeys(fn (string $provider): array => [
                                        $provider => match ($provider) {
                                            'systech' => 'Systech',
                                            'unitrace' => 'UniTrace',
                                            default => $provider,
                                        },
                                    ])
                                    ->all();
                            })
                            ->columns(2)
                            ->helperText('Empty means this tenant cannot register for hub routing. Tenant must also set GLN and use App Register.'),
                    ]),
                Section::make('Kill switches')
                    ->compact()
                    ->columns(['md' => 2])
                    ->visibleOn('edit')
                    ->visible(fn (): bool => auth('admin')->user()?->can(Permissions::TenantsManage) ?? false)
                    ->description('Disable high-risk features for this organization without suspending the account. Tenant admins cannot override these settings.')
                    ->schema([
                        Toggle::make('kill_switch_outbound_epcis')
                            ->label('Block outbound EPCIS')
                            ->helperText('Stops outbound transmit and ship-order EPCIS job enqueue.'),
                        Toggle::make('kill_switch_inbound_epcis')
                            ->label('Block inbound EPCIS')
                            ->helperText('Rejects inbound webhooks, hub routing, AS2 MDN, SFTP polling, and Sanctum POST /api/v1/epcis/inbound.'),
                        Toggle::make('kill_switch_sanctum_api')
                            ->label('Block Sanctum API')
                            ->helperText('Returns 403 for all /api/v1/* routes on this tenant host.'),
                        Toggle::make('kill_switch_wms_webhooks')
                            ->label('Block WMS ship-confirm webhooks')
                            ->helperText('Rejects WMS ship-confirm bridge callbacks.'),
                    ]),
                Section::make('Enterprise SSO (OIDC)')
                    ->compact()
                    ->columns(['md' => 2])
                    ->visibleOn('edit')
                    ->visible(fn (): bool => auth('admin')->user()?->can(Permissions::TenantsManage) ?? false)
                    ->description('Per-tenant Microsoft Entra ID, Okta, or generic OpenID Connect. Redirect URI: https://{tenant-host}/auth/oidc/callback')
                    ->schema([
                        Toggle::make('sso_enabled')
                            ->label('Enable SSO')
                            ->live(),
                        Toggle::make('sso_only')
                            ->label('SSO only (hide password login)')
                            ->visible(fn (Get $get): bool => (bool) $get('sso_enabled')),
                        Select::make('sso_provider')
                            ->label('Identity provider')
                            ->options(OidcProvider::options())
                            ->native(false)
                            ->visible(fn (Get $get): bool => (bool) $get('sso_enabled')),
                        TextInput::make('sso_issuer')
                            ->label('Issuer URL')
                            ->url()
                            ->maxLength(255)
                            ->helperText('Entra: https://login.microsoftonline.com/{tenant}/v2.0 — Okta: https://{org}.okta.com')
                            ->visible(fn (Get $get): bool => (bool) $get('sso_enabled')),
                        TextInput::make('sso_client_id')
                            ->label('Client ID')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('sso_enabled')),
                        TextInput::make('sso_client_secret')
                            ->label('Client secret')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Leave blank to keep the existing secret.')
                            ->visible(fn (Get $get): bool => (bool) $get('sso_enabled')),
                        TextInput::make('sso_entra_tenant_id')
                            ->label('Entra directory (tenant) ID')
                            ->maxLength(64)
                            ->visible(fn (Get $get): bool => (bool) $get('sso_enabled') && $get('sso_provider') === OidcProvider::Entra->value),
                        Select::make('sso_jit_default_role')
                            ->label('JIT default role')
                            ->options(fn (?Tenant $record): array => $record
                                ? TenantRole::optionsForProfile(
                                    TenantProfile::tryFrom((string) $record->profile) ?? TenantProfile::Pharmacy
                                )
                                : [])
                            ->native(false)
                            ->helperText('Assigned when SSO creates a new user. Never creates Owners automatically unless selected.')
                            ->visible(fn (Get $get): bool => (bool) $get('sso_enabled')),
                        TextInput::make('sso_allowed_email_domains')
                            ->label('Allowed email domains (JIT)')
                            ->helperText('Comma-separated. Empty allows any domain. Example: acme.com, acme.co')
                            ->visible(fn (Get $get): bool => (bool) $get('sso_enabled'))
                            ->columnSpanFull(),
                    ]),
                Section::make('Address')
                    ->compact()
                    ->columns(['md' => 2, 'lg' => 3])
                    ->schema([
                        TextInput::make('street_address')
                            ->label('Street address')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('street_address_2')
                            ->label('Street address 2')
                            ->maxLength(255)
                            ->nullable()
                            ->columnSpanFull(),
                        TextInput::make('city')
                            ->maxLength(255),
                        Select::make('state')
                            ->options(UsState::selectOptions())
                            ->searchable()
                            ->nullable()
                            ->native(false),
                        TextInput::make('zipcode')
                            ->label('ZIP code')
                            ->maxLength(20),
                        TextInput::make('country_code')
                            ->label('Country code')
                            ->default('US')
                            ->maxLength(3),
                    ]),
            ]);
    }

    /**
     * Admin edits tenant identity from the central panel, so the collision check has to
     * reach into the tenant database itself. Each field only reports the conflict it can
     * fix, so the operator is not told to change a prefix when the GLN is the problem.
     */
    private static function identityConflictRule(Get $get, ?Tenant $record, string $field): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($get, $record, $field): void {
            if (! $record instanceof Tenant) {
                return;
            }

            try {
                app(AssertOrganizationSsccIdentity::class)->forTenant(
                    $record,
                    is_string($get('gln')) ? $get('gln') : null,
                    is_string($get('company_prefix')) ? $get('company_prefix') : null,
                );
            } catch (OrganizationIdentityConflictException $e) {
                if ($e->field === $field) {
                    $fail($e->getMessage());
                }
            }
        };
    }
}
