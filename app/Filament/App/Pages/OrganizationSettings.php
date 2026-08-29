<?php

namespace App\Filament\App\Pages;

use App\Enums\ClientPrintBridge;
use App\Enums\SsccAllocationMode;
use App\Enums\TenantProfile;
use App\Exceptions\OrganizationIdentityConflictException;
use App\Filament\App\Resources\SsccNumberRanges\SsccNumberRangeResource;
use App\Models\Site;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Dashboard\DashboardWidgetCatalog;
use App\Support\Gs1\GlnRules;
use App\Support\Gs1\Sgln;
use App\Support\Places\UsState;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use App\Support\TenantSsccSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class OrganizationSettings extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $navigationLabel = 'Organization';

    protected static ?string $title = 'Organization';

    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.app.pages.organization-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! TenantFeatures::forTenant(tenant())->supportsMasterData()) {
            return false;
        }

        return JobRoleAccess::canAccessOrganizationSettings();
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Company GLN, default receive/ship sites, and compliance contacts for DSCSA operations.';
    }

    protected function fillForm(): void
    {
        $tenant = tenant();
        $settings = TenantSettings::forTenant($tenant);

        $sscc = TenantSsccSettings::resolve($tenant);

        $this->form->fill(array_merge([
            'name' => $tenant?->name,
            'organization_type' => $tenant?->profile?->tenantType()->label(),
            'gln' => $settings->gln(),
            'company_prefix' => $settings->companyPrefix(),
            'receiving_state' => $settings->receivingState(),
            'default_receive_site_id' => $settings->defaultReceiveSiteId(),
            'default_ship_from_site_id' => $settings->defaultShipFromSiteId(),
            'require_ti_for_scan_first' => $settings->requireTiForScanFirst(),
            'receiving_edge_mode' => ReceivingPolicy::forTenant($tenant)->edgeMode()->value,
            'job_roles_enabled' => $settings->jobRolesEnabled(),
            'compliance_contact_name' => $settings->complianceContactName(),
            'compliance_contact_email' => $settings->complianceContactEmail(),
            'it_contact_name' => $settings->itContactName(),
            'it_contact_email' => $settings->itContactEmail(),
            'serialization_contact_name' => $settings->serializationContactName(),
            'serialization_contact_email' => $settings->serializationContactEmail(),
            'sscc_extension_digit' => $sscc['extension_digit'],
            'sscc_enforce_forward_only' => $sscc['enforce_forward_only'],
            'sscc_default_allocation_mode' => $sscc['default_allocation_mode']->value,
            'sscc_low_water_mark' => $sscc['low_water_mark'],
            'client_print_bridge' => $settings->clientPrintBridge()->value,
            'l3_enabled' => $settings->l3Enabled(),
            'l3_provider' => $settings->l3Provider(),
            'l3_endpoint_url' => $settings->l3EndpointUrl(),
            'l3_api_key' => null,
            'wms_bridge_api_key' => null,
            'wms_receive_confirm_url' => $settings->wmsReceiveConfirmUrl(),
            'dashboard_allow_user_customize' => $settings->dashboardAllowUserCustomize(),
            'dashboard_allowed' => array_keys(array_filter($settings->dashboardAllowed())),
            'dashboard_defaults' => array_keys(array_filter($settings->dashboardDefaults())),
            'pharmacy_simplified_nav' => $settings->pharmacySimplifiedNavEnabled(),
            'alert_digest_enabled' => $settings->alertDigestEnabled(),
            'alert_digest_frequency' => $settings->alertDigestFrequency(),
            'email_portal_on_ship' => $settings->emailPortalOnShipEnabled(),
        ], $settings->organizationAddress()));
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
                Section::make('Identity')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Organization name')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('organization_type')
                            ->label('Organization type')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(fn (): string => sprintf(
                                'Profile: %s — managed by TracePharma support',
                                tenant()?->profile?->label() ?? '—',
                            )),
                        GlnRules::input('gln', 'Company GLN')
                            ->nullable()
                            ->helperText('13-digit GS1 Global Location Number for your company.'),
                        TextInput::make('company_prefix')
                            ->label('Company prefix (GCP)')
                            ->maxLength(11)
                            ->nullable()
                            ->rules(['nullable', 'regex:/^\d{6,11}$/'])
                            ->rule(function (): \Closure {
                                return function (string $attribute, mixed $value, \Closure $fail): void {
                                    try {
                                        TenantSettings::assertValidCompanyPrefix(
                                            is_string($value) ? $value : null,
                                            is_string($this->data['gln'] ?? null) ? $this->data['gln'] : null,
                                        );
                                    } catch (\InvalidArgumentException $e) {
                                        $fail($e->getMessage());
                                    }
                                };
                            })
                            ->helperText('6–11 digit GS1 Company Prefix used for SGLNs and SSCC number ranges. Ranges must match this GCP.'),
                        Select::make('receiving_state')
                            ->label('Preferred receiving state')
                            ->options(UsState::selectOptions())
                            ->searchable()
                            ->nullable()
                            ->native(false)
                            ->helperText('Optional badge label. Partner ATP licenses are evaluated against organization facility jurisdictions; this is the fallback when no org sites have a state.'),
                    ]),
                Section::make('Address')
                    ->compact()
                    ->columns(['md' => 2])
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
                Section::make('Default sites')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        Select::make('default_receive_site_id')
                            ->label('Default receive site')
                            ->options(fn (): array => $this->activeSitesWithGlnOptions())
                            ->searchable()
                            ->nullable()
                            ->native(false)
                            ->helperText('Used when inbound EPCIS does not resolve a ship-to site.'),
                        Select::make('default_ship_from_site_id')
                            ->label('Default ship-from site')
                            ->options(fn (): array => $this->activeSitesWithGlnOptions())
                            ->searchable()
                            ->nullable()
                            ->native(false)
                            ->visible(fn (): bool => $this->showsShipFromSite())
                            ->helperText('Default origin site for outbound shipping.'),
                        Toggle::make('require_ti_for_scan_first')
                            ->label('Require TI for scan-first receive')
                            ->helperText('When on, block scan-first confirms that lack shipping/commissioning TI in the repository. Off = soft warn (default).')
                            ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsReceiving()),
                        Select::make('receiving_edge_mode')
                            ->label('Receive SOP')
                            ->options(ReceivingEdgeMode::options())
                            ->native(false)
                            ->helperText('Overrides the profile default for sealed vs open-count receive.')
                            ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsReceiving()),
                    ]),
                Section::make('Access')
                    ->compact()
                    ->description('Control whether job roles limit menus and actions.')
                    ->visible(fn (): bool => JobRoleAccess::isOwner()
                        || (auth()->user()?->can(Permissions::UsersManage) ?? false))
                    ->schema([
                        Toggle::make('job_roles_enabled')
                            ->label('Limit access by job role')
                            ->helperText('When off, users with Manage users (Owners and system administrators) manage accounts, and everyone with site access sees the same menus for your business type. When on, each user’s job role further limits menus — we sync role permissions automatically when you turn this on. With roles on, users with the Master data capability can create and edit sites, products, and trading partners (not Owner-only); deletes stay with Owner and master-data administrator personas.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Pharmacy navigation')
                    ->compact()
                    ->visible(fn (): bool => tenant()?->profile === TenantProfile::Pharmacy)
                    ->description('Trim navigation for independent pharmacies — receive, verify, pharmacy outbound desk, and compliance essentials.')
                    ->schema([
                        Toggle::make('pharmacy_simplified_nav')
                            ->label('Simplified navigation')
                            ->helperText('When on, hides transfer, pack, ship order, analytics, and other wholesaler floor workflows.')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
                Section::make('Notifications')
                    ->compact()
                    ->description('Compliance Alert Center digests and customer portal ship notices.')
                    ->schema([
                        Toggle::make('alert_digest_enabled')
                            ->label('Alert center email digest')
                            ->helperText('Email compliance and IT contacts (or Owners) when Alert Center has critical or warning signals.')
                            ->default(true)
                            ->live()
                            ->columnSpanFull(),
                        Select::make('alert_digest_frequency')
                            ->label('Digest frequency')
                            ->options([
                                'daily' => 'Daily',
                                'weekly' => 'Weekly (Mondays)',
                            ])
                            ->default('daily')
                            ->visible(fn ($get): bool => (bool) $get('alert_digest_enabled'))
                            ->required(fn ($get): bool => (bool) $get('alert_digest_enabled')),
                        Toggle::make('email_portal_on_ship')
                            ->label('Email customer portal link on ship')
                            ->helperText('After TI is authored, email the trading partner contact a signed portal link. No portal login accounts.')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
                Section::make('Dashboard')
                    ->compact()
                    ->description('Choose which widgets users may show on home, and the defaults for people who have not customized yet. Home shows at most '
                        .DashboardWidgetCatalog::HOME_CAP
                        .' widgets.')
                    ->visible(fn (): bool => JobRoleAccess::isOwner())
                    ->schema([
                        Toggle::make('dashboard_allow_user_customize')
                            ->label('Allow users to customize their dashboard')
                            ->helperText('When off, everyone sees the organization defaults. When on, each user can pick among allowed widgets.')
                            ->columnSpanFull(),
                        CheckboxList::make('dashboard_allowed')
                            ->label('Allowed widgets')
                            ->options(fn (): array => $this->dashboardWidgetOptions())
                            ->descriptions(fn (): array => $this->dashboardWidgetDescriptions())
                            ->columns(2)
                            ->columnSpanFull(),
                        CheckboxList::make('dashboard_defaults')
                            ->label('Default widgets on home')
                            ->helperText('Used when a user has not saved their own dashboard.')
                            ->options(fn (): array => $this->dashboardWidgetOptions())
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Contacts')
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('compliance_contact_name')
                            ->label('Compliance contact name')
                            ->maxLength(255)
                            ->nullable(),
                        TextInput::make('compliance_contact_email')
                            ->label('Compliance contact email')
                            ->email()
                            ->maxLength(255)
                            ->nullable(),
                        TextInput::make('it_contact_name')
                            ->label('IT contact name')
                            ->maxLength(255)
                            ->nullable(),
                        TextInput::make('it_contact_email')
                            ->label('IT contact email')
                            ->email()
                            ->maxLength(255)
                            ->nullable(),
                        TextInput::make('serialization_contact_name')
                            ->label('Serialization contact name')
                            ->maxLength(255)
                            ->nullable(),
                        TextInput::make('serialization_contact_email')
                            ->label('Serialization contact email')
                            ->email()
                            ->maxLength(255)
                            ->nullable(),
                    ]),
                Section::make('SSCC labeling')
                    ->compact()
                    ->columns(['md' => 2])
                    ->visible(function (): bool {
                        $features = TenantFeatures::forTenant(tenant());

                        return $features->supportsPacking() || $features->supportsSsccLabeling();
                    })
                    ->description('Defaults for pallet SSCC-18 generation. Company prefix is under Identity. Issue serials from SSCC Number Ranges (tenant-, site-, or partner-scoped).')
                    ->headerActions([
                        Action::make('manageSsccNumberRanges')
                            ->label('SSCC Number Ranges')
                            ->icon(Heroicon::OutlinedHashtag)
                            ->url(fn (): string => SsccNumberRangeResource::getUrl('index', panel: 'app'))
                            ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsSsccLabeling()
                                && SsccNumberRangeResource::canAccess()),
                    ])
                    ->schema([
                        TextInput::make('sscc_extension_digit')
                            ->label('Extension digit')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(9)
                            ->default(0)
                            ->required()
                            ->helperText('Default extension digit for new number ranges and label generation.'),
                        Select::make('sscc_default_allocation_mode')
                            ->label('Default allocation mode')
                            ->options(collect(SsccAllocationMode::cases())->mapWithKeys(
                                fn (SsccAllocationMode $mode): array => [$mode->value => $mode->label()]
                            ))
                            ->native(false)
                            ->required(),
                        Toggle::make('sscc_enforce_forward_only')
                            ->label('Enforce forward-only serials')
                            ->helperText('Require new serials greater than the last generated value.')
                            ->default(true),
                        TextInput::make('sscc_low_water_mark')
                            ->label('Pool low-water threshold')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Alert when remaining serials in the pool fall below this count.')
                            ->required(),
                        Select::make('client_print_bridge')
                            ->label('Default label print bridge')
                            ->options(collect(ClientPrintBridge::cases())->mapWithKeys(
                                fn (ClientPrintBridge $bridge): array => [$bridge->value => $bridge->label()]
                            ))
                            ->native(false)
                            ->required()
                            ->helperText('Used for network printers and as the session default. Printers set to QZ Tray or Zebra Browser Print always use that bridge. Users can override per reprint.')
                            ->columnSpanFull(),
                    ]),
                Section::make('External L3 serialization')
                    ->compact()
                    ->columns(['md' => 2])
                    ->visible(fn (): bool => tenant()?->profile === TenantProfile::Manufacturer)
                    ->description('Lean bridge settings for an external Level 3 serialization provider. Full L3 commissioning workflows ship later.')
                    ->schema([
                        Toggle::make('l3_enabled')
                            ->label('Enable external L3 bridge')
                            ->helperText('When on, TracePharma can forward commissioning events to your L3 provider endpoint.')
                            ->columnSpanFull(),
                        Select::make('l3_provider')
                            ->label('L3 provider')
                            ->options([
                                'systech' => 'Systech',
                                'tracelink' => 'TraceLink',
                                'other' => 'Other',
                            ])
                            ->nullable()
                            ->native(false)
                            ->searchable(),
                        TextInput::make('l3_endpoint_url')
                            ->label('L3 endpoint URL')
                            ->url()
                            ->maxLength(2048)
                            ->nullable()
                            ->columnSpanFull()
                            ->helperText('HTTPS endpoint for commissioning or status callbacks from TracePharma. Do not embed credentials in the URL.'),
                        TextInput::make('l3_api_key')
                            ->label('L3 API key')
                            ->password()
                            ->revealable()
                            ->nullable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->placeholder(fn (): string => TenantSettings::forTenant(tenant())->l3ApiKey() !== null
                                ? '••••••••••••'
                                : '')
                            ->helperText('Write-only. Leave blank to keep the current key.')
                            ->columnSpanFull(),
                    ]),
                Section::make('WMS ship-confirm bridge')
                    ->compact()
                    ->visible(fn (): bool => $this->showsWmsBridgeSection())
                    ->description('Per-tenant API key for warehouse ship-confirm webhooks (X-Wms-Api-Key).')
                    ->schema([
                        TextInput::make('wms_bridge_api_key')
                            ->label('WMS bridge API key')
                            ->password()
                            ->revealable()
                            ->nullable()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->placeholder(fn (): string => TenantSettings::forTenant(tenant())->wmsBridgeApiKey() !== null
                                ? '••••••••••••'
                                : '')
                            ->helperText('Write-only. Leave blank to keep the current key.')
                            ->columnSpanFull(),
                        TextInput::make('wms_receive_confirm_url')
                            ->label('WMS receive-confirm URL')
                            ->url()
                            ->maxLength(2048)
                            ->nullable()
                            ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsReceiving())
                            ->helperText('Optional HTTPS endpoint. After inbound ASN or scan-first receive complete, TracePharma POSTs a receive-confirm payload. Leave blank to disable.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $previousCompanyPrefix = TenantSettings::forTenant(tenant())->companyPrefix();

        $organization = [
            'gln' => $data['gln'] ?? null,
            'company_prefix' => $data['company_prefix'] ?? null,
            'receiving_state' => $data['receiving_state'] ?? null,
            'street_address' => $data['street_address'] ?? null,
            'street_address_2' => $data['street_address_2'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zipcode' => $data['zipcode'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'default_receive_site_id' => $data['default_receive_site_id'] ?? null,
            'default_ship_from_site_id' => $data['default_ship_from_site_id'] ?? null,
            'compliance_contact_name' => $data['compliance_contact_name'] ?? null,
            'compliance_contact_email' => $data['compliance_contact_email'] ?? null,
            'it_contact_name' => $data['it_contact_name'] ?? null,
            'it_contact_email' => $data['it_contact_email'] ?? null,
            'serialization_contact_name' => $data['serialization_contact_name'] ?? null,
            'serialization_contact_email' => $data['serialization_contact_email'] ?? null,
            'client_print_bridge' => $data['client_print_bridge'] ?? null,
        ];

        if (TenantFeatures::forTenant(tenant())->supportsReceiving()) {
            $organization['require_ti_for_scan_first'] = (bool) ($data['require_ti_for_scan_first'] ?? false);
            $organization['receiving_edge_mode'] = $data['receiving_edge_mode'] ?? null;
        }

        $settings = TenantSettings::forTenant(tenant());
        $previouslyEnabled = $settings->jobRolesEnabled();
        $wantsJobRoles = (bool) ($data['job_roles_enabled'] ?? false);
        $canToggleJobRoles = JobRoleAccess::isOwner()
            || (auth()->user()?->can(Permissions::UsersManage) ?? false);

        if ($canToggleJobRoles) {
            if ($wantsJobRoles && ! $previouslyEnabled) {
                $this->seedJobRolePermissionsOrFail();
            }

            $organization['job_roles_enabled'] = $wantsJobRoles;
        }

        if (tenant()?->profile === TenantProfile::Manufacturer) {
            $organization['l3_enabled'] = (bool) ($data['l3_enabled'] ?? false);
            $organization['l3_provider'] = $data['l3_provider'] ?? null;
            $organization['l3_endpoint_url'] = $data['l3_endpoint_url'] ?? null;

            if (filled($data['l3_api_key'] ?? null)) {
                $organization['l3_api_key'] = $data['l3_api_key'];
            }
        }

        if ($this->showsWmsBridgeSection() && filled($data['wms_bridge_api_key'] ?? null)) {
            $organization['wms_bridge_api_key'] = $data['wms_bridge_api_key'];
        }

        if (TenantFeatures::forTenant(tenant())->supportsReceiving()) {
            $organization['wms_receive_confirm_url'] = $data['wms_receive_confirm_url'] ?? null;
        }

        if (JobRoleAccess::isOwner()) {
            $organization['dashboard_allow_user_customize'] = (bool) ($data['dashboard_allow_user_customize'] ?? true);
            $organization['dashboard_allowed'] = $this->dashboardCheckboxListToFlags($data['dashboard_allowed'] ?? []);
            $organization['dashboard_defaults'] = $this->dashboardCheckboxListToFlags($data['dashboard_defaults'] ?? []);
        }

        if (tenant()?->profile === TenantProfile::Pharmacy) {
            $organization['pharmacy_simplified_nav'] = (bool) ($data['pharmacy_simplified_nav'] ?? true);
        }

        $organization['alert_digest_enabled'] = (bool) ($data['alert_digest_enabled'] ?? true);
        $organization['alert_digest_frequency'] = (string) ($data['alert_digest_frequency'] ?? 'daily');
        $organization['email_portal_on_ship'] = (bool) ($data['email_portal_on_ship'] ?? true);

        try {
            TenantSettings::forTenant(tenant())->saveOrganization($organization);
        } catch (OrganizationIdentityConflictException $e) {
            throw ValidationException::withMessages([
                'data.'.$e->field => $e->getMessage(),
            ]);
        } catch (\InvalidArgumentException $e) {
            $field = match (true) {
                str_contains($e->getMessage(), 'L3 endpoint URL') => 'data.l3_endpoint_url',
                str_contains($e->getMessage(), 'WMS receive-confirm URL') => 'data.wms_receive_confirm_url',
                default => 'data.company_prefix',
            };

            throw ValidationException::withMessages([
                $field => $e->getMessage(),
            ]);
        }

        $features = TenantFeatures::forTenant(tenant());
        if ($features->supportsPacking() || $features->supportsSsccLabeling()) {
            TenantSsccSettings::persist([
                'extension_digit' => (int) ($data['sscc_extension_digit'] ?? 0),
                'enforce_forward_only' => (bool) ($data['sscc_enforce_forward_only'] ?? true),
                'default_allocation_mode' => (string) ($data['sscc_default_allocation_mode'] ?? SsccAllocationMode::Sequential->value),
                'low_water_mark' => max(1, (int) ($data['sscc_low_water_mark'] ?? config('sscc.default_low_water_mark', 5000))),
            ]);
        }

        $notification = Notification::make()
            ->title('Organization settings saved')
            ->success();

        // The prefix decides where our GLNs split, so changing it re-authors the SGLN of
        // every facility issued under it. Say so: those URNs identify our locations on
        // the EPCIS documents partners already hold.
        if (TenantSettings::forTenant(tenant())->companyPrefix() !== $previousCompanyPrefix) {
            $notification->body('Company prefix changed — organization site and location device SGLNs were re-derived under it.');
        }

        $notification->send();

        $this->fillForm();
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
     * @return array<Action|ActionGroup>
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

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('ssccNumberRanges')
                ->label('SSCC Number Ranges')
                ->icon(Heroicon::OutlinedHashtag)
                ->color('gray')
                ->url(fn (): string => SsccNumberRangeResource::getUrl('index', panel: 'app'))
                ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsSsccLabeling()
                    && SsccNumberRangeResource::canAccess()),
            Action::make('exportGlns')
                ->label('Export GLNs')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->tooltip('Download company and site GLNs as CSV for partner onboarding and EPCIS location setup.')
                ->action(fn (): StreamedResponse => $this->exportGlnsCsv()),
        ];
    }

    private function exportGlnsCsv(): StreamedResponse
    {
        $tenant = tenant();
        $rawCompanyGln = TenantSettings::forTenant($tenant)->gln();
        $companyGln = Sgln::normalizeGln($rawCompanyGln) ?? $rawCompanyGln;
        $filename = 'organization-glns-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($tenant, $companyGln): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['type', 'id', 'name', 'gln', 'sgln', 'is_headquarters']);
            fputcsv($out, [
                'company',
                '',
                $tenant?->name ?? '',
                $companyGln ?? '',
                '',
                '',
            ]);

            if (tenancy()->initialized) {
                Site::query()
                    ->orderByDesc('is_headquarters')
                    ->orderBy('name')
                    ->get(['id', 'name', 'gln', 'sgln', 'is_headquarters'])
                    ->each(function (Site $site) use ($out): void {
                        $normalizedGln = Sgln::normalizeGln($site->gln) ?? $site->gln;
                        $rawSgln = $site->getAttribute('sgln');
                        $hint = is_string($rawSgln) && $rawSgln !== '' ? $rawSgln : null;
                        // Prefer a GS1-parseable URN via Sgln; fall back to stored/generated attribute.
                        $sgln = Sgln::resolveUrn($normalizedGln, $hint) ?? $hint ?? '';

                        fputcsv($out, [
                            'site',
                            $site->getKey(),
                            $site->name,
                            $normalizedGln ?? '',
                            $sgln,
                            $site->is_headquarters ? '1' : '0',
                        ]);
                    });
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Organization-owned active sites with GLN (same set as receive/transfer pickers).
     *
     * @return array<int, string>
     */
    private function activeSitesWithGlnOptions(): array
    {
        if (! tenancy()->initialized) {
            return [];
        }

        return EligibleReceiveSites::forOrganization()
            ->get(['id', 'name', 'gln'])
            ->mapWithKeys(fn (Site $site): array => [
                (int) $site->getKey() => $site->name.' ('.$site->gln.')',
            ])
            ->all();
    }

    private function showsShipFromSite(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations();
    }

    private function showsWmsBridgeSection(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        return $features->supportsOutboundIntegrations() || $features->supportsReceiving();
    }

    /**
     * @return array<string, string>
     */
    private function dashboardWidgetOptions(): array
    {
        $options = [];

        foreach ($this->availableDashboardDefinitions() as $definition) {
            $options[$definition['key']] = $definition['label'];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function dashboardWidgetDescriptions(): array
    {
        $descriptions = [];

        foreach ($this->availableDashboardDefinitions() as $definition) {
            $descriptions[$definition['key']] = $definition['description'];
        }

        return $descriptions;
    }

    /**
     * @return list<array{
     *     key: string,
     *     kind: 'lean'|'analytics',
     *     label: string,
     *     description: string,
     *     defaultOnHome: bool,
     *     signal: 'flow'|'friction'|'recovery'|'action'
     * }>
     */
    private function availableDashboardDefinitions(): array
    {
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;
        $features = TenantFeatures::forTenant(tenant());
        $definitions = [];

        foreach (DashboardWidgetCatalog::all() as $definition) {
            if ($definition['key'] === 'site_comparison') {
                $featureOn = $features->supportsReceiving() || $features->supportsOutboundIntegrations();

                if ($featureOn) {
                    $definitions[] = $definition;
                }

                continue;
            }

            if (DashboardWidgetCatalog::isAvailable($definition['key'], $features, $user)) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @return array<string, bool>
     */
    private function dashboardCheckboxListToFlags(mixed $selected): array
    {
        $selected = is_array($selected) ? array_map(strval(...), $selected) : [];
        $flags = [];

        foreach (array_keys($this->dashboardWidgetOptions()) as $key) {
            $flags[$key] = in_array($key, $selected, true);
        }

        return $flags;
    }

    private function seedJobRolePermissionsOrFail(): void
    {
        $tenant = tenant();
        $profile = $tenant?->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) ($tenant?->profile ?? '')) ?? TenantProfile::Pharmacy;

        app(TenantRoleSeeder::class)->seedForProfile($profile);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $user->refresh();

        if (JobRoleAccess::isOwner($user) && ! $user->can(Permissions::NavMasterData)) {
            throw ValidationException::withMessages([
                'data.job_roles_enabled' => 'Owner role permissions did not sync — you would lose Settings access. Run php artisan tracepharma:seed-tenant-job-roles or contact support, then try again.',
            ]);
        }
    }

    public static function getDocumentation(): array|string
    {
        return 'settings.settings-hub';
    }
}
