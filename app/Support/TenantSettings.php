<?php

namespace App\Support;

use App\Actions\MasterData\RederiveOrganizationSglns;
use App\Enums\ClientPrintBridge;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Dashboard\DashboardWidgetCatalog;
use App\Support\Gs1\AssertOrganizationSsccIdentity;
use App\Support\Tenancy\TenantKillSwitches;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Typed accessors for tenant organization settings.
 *
 * Stancl storage:
 * - `gln` and `company_prefix` are real custom columns on `tenants`
 * - `receiving_state` is a virtual attribute (JSON `data` column)
 * - contacts, organization address, default site ids, and onboarding live under the virtual `settings` key
 */
class TenantSettings
{
    /** @var list<string> */
    public const ADDRESS_KEYS = [
        'street_address',
        'street_address_2',
        'city',
        'state',
        'zipcode',
        'country_code',
    ];

    public function __construct(
        protected ?Tenant $tenant,
    ) {}

    public static function forTenant(?Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function gln(): ?string
    {
        $value = $this->tenant?->gln;

        return blank($value) ? null : (string) $value;
    }

    public function setGln(?string $gln): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $this->tenant->gln = blank($gln) ? null : trim($gln);

        return $this;
    }

    public function companyPrefix(): ?string
    {
        $value = $this->tenant?->company_prefix;

        return blank($value) ? null : (string) $value;
    }

    public function setCompanyPrefix(?string $prefix): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $this->tenant->company_prefix = self::normalizeCompanyPrefix($prefix);

        return $this;
    }

    /**
     * Digits-only GS1 company prefix, or null when blank.
     */
    public static function normalizeCompanyPrefix(?string $prefix): ?string
    {
        if ($prefix === null || trim($prefix) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $prefix) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertValidCompanyPrefix(?string $prefix, ?string $gln = null): void
    {
        $normalized = self::normalizeCompanyPrefix($prefix);

        if ($normalized === null) {
            return;
        }

        $length = strlen($normalized);

        if ($length < 6 || $length > 11 || ! ctype_digit($normalized)) {
            throw new \InvalidArgumentException(
                'Company prefix must be 6–11 digits (GS1 Company Prefix).',
            );
        }

        $glnDigits = preg_replace('/\D+/', '', (string) $gln) ?? '';
        if (strlen($glnDigits) === 13) {
            $body12 = substr($glnDigits, 0, 12);
            if (! str_starts_with($body12, $normalized)) {
                throw new \InvalidArgumentException(
                    'Company prefix must match the start of the company GLN body.',
                );
            }
        }
    }

    public function receivingState(): ?string
    {
        $value = $this->tenant?->receiving_state;

        return blank($value) ? null : (string) $value;
    }

    public function setReceivingState(?string $state): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $this->tenant->receiving_state = blank($state) ? null : trim($state);

        return $this;
    }

    public function defaultReceiveSiteId(): ?int
    {
        $id = $this->setting('default_receive_site_id');

        return $id !== null && $id !== '' ? (int) $id : null;
    }

    public function setDefaultReceiveSiteId(?int $siteId): self
    {
        return $this->putSetting('default_receive_site_id', $siteId);
    }

    /**
     * When true, scan-first receive hard-blocks confirms that lack TI (shipping/commissioning) in-repo.
     * Default soft-warn when false.
     */
    public function requireTiForScanFirst(): bool
    {
        return (bool) data_get($this->settingsBag(), 'receiving.require_ti_for_scan_first', false);
    }

    public function setRequireTiForScanFirst(bool $require): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();
        data_set($settings, 'receiving.require_ti_for_scan_first', $require);
        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * When true, Filament nav/actions also require Spatie job-role capability permissions.
     * Default off: profile features + site membership only.
     */
    public function jobRolesEnabled(): bool
    {
        return (bool) data_get($this->settingsBag(), 'access.job_roles_enabled', false);
    }

    public function setJobRolesEnabled(bool $enabled): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();
        data_set($settings, 'access.job_roles_enabled', $enabled);
        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    public function dashboardAllowUserCustomize(): bool
    {
        return (bool) data_get($this->settingsBag(), 'dashboard.allow_user_customize', true);
    }

    public function setDashboardAllowUserCustomize(bool $allow): self
    {
        return $this->putDashboardSetting('allow_user_customize', $allow);
    }

    /**
     * @return array<string, bool>
     */
    public function dashboardDefaults(): array
    {
        return $this->dashboardFlagMap('defaults', useCatalogHomeDefault: true);
    }

    /**
     * @param  array<string, bool>  $defaults
     */
    public function setDashboardDefaults(array $defaults): self
    {
        return $this->putDashboardSetting('defaults', $this->normalizeDashboardFlags($defaults));
    }

    /**
     * Missing keys stay allowed (lean home defaults always; analytics until an owner disables them).
     *
     * @return array<string, bool>
     */
    public function dashboardAllowed(): array
    {
        return $this->dashboardFlagMap('allowed', missingDefault: true);
    }

    /**
     * @param  array<string, bool>  $allowed
     */
    public function setDashboardAllowed(array $allowed): self
    {
        return $this->putDashboardSetting('allowed', $this->normalizeDashboardFlags($allowed));
    }

    /**
     * Default workstation label-print bridge (network TCP vs local client agents).
     */
    public function clientPrintBridge(): ClientPrintBridge
    {
        $value = data_get($this->settingsBag(), 'labeling.client_print_bridge');

        return ClientPrintBridge::tryFromMixed($value) ?? ClientPrintBridge::NetworkTcp;
    }

    public function setClientPrintBridge(ClientPrintBridge|string|null $bridge): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $resolved = $bridge instanceof ClientPrintBridge
            ? $bridge
            : (ClientPrintBridge::tryFromMixed($bridge) ?? ClientPrintBridge::NetworkTcp);

        $settings = $this->settingsBag();
        data_set($settings, 'labeling.client_print_bridge', $resolved->value);
        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * External L3 serialization provider bridge (manufacturer profile).
     */
    public function l3Enabled(): bool
    {
        return (bool) data_get($this->settingsBag(), 'l3.enabled', false);
    }

    public function setL3Enabled(bool $enabled): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();
        data_set($settings, 'l3.enabled', $enabled);
        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    public function l3Provider(): ?string
    {
        $value = data_get($this->settingsBag(), 'l3.provider');

        return blank($value) ? null : (string) $value;
    }

    public function setL3Provider(?string $provider): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();
        data_set($settings, 'l3.provider', blank($provider) ? null : trim($provider));
        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    public function l3EndpointUrl(): ?string
    {
        $value = data_get($this->settingsBag(), 'l3.endpoint_url');

        return blank($value) ? null : (string) $value;
    }

    public function setL3EndpointUrl(?string $url): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $normalized = blank($url) ? null : trim($url);
        self::assertL3EndpointUrlWithoutUserinfo($normalized);

        $settings = $this->settingsBag();
        data_set($settings, 'l3.endpoint_url', $normalized);
        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertL3EndpointUrlWithoutUserinfo(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $parsed = parse_url($url);

        if ($parsed === false) {
            throw new \InvalidArgumentException('L3 endpoint URL is not valid.');
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \InvalidArgumentException(
                'L3 endpoint URL must not include credentials. Use the L3 API key field instead.',
            );
        }
    }

    /**
     * Per-tenant L3 serialization bridge API key (encrypted at rest in settings JSON).
     */
    public function l3ApiKey(): ?string
    {
        $encrypted = data_get($this->settingsBag(), 'l3.api_key');

        if (blank($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setL3ApiKey(?string $key): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();

        if (blank($key)) {
            data_set($settings, 'l3.api_key', null);
        } else {
            data_set($settings, 'l3.api_key', Crypt::encryptString(trim($key)));
        }

        if (data_get($settings, 'l3') === []) {
            unset($settings['l3']);
        }

        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * Per-tenant WMS ship-confirm bridge API key (encrypted at rest in settings JSON).
     */
    public function wmsBridgeApiKey(): ?string
    {
        $encrypted = data_get($this->settingsBag(), 'integrations.wms_bridge_api_key');

        if (blank($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setWmsBridgeApiKey(?string $key): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();

        if (blank($key)) {
            data_set($settings, 'integrations.wms_bridge_api_key', null);
        } else {
            data_set($settings, 'integrations.wms_bridge_api_key', Crypt::encryptString(trim($key)));
        }

        if (data_get($settings, 'integrations') === []) {
            unset($settings['integrations']);
        }

        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * Per-tenant VRS responder API key (encrypted at rest in settings JSON).
     */
    public function vrsResponderApiKey(): ?string
    {
        $encrypted = data_get($this->settingsBag(), 'integrations.vrs_responder_api_key');

        if (blank($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setVrsResponderApiKey(?string $key): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();

        if (blank($key)) {
            data_set($settings, 'integrations.vrs_responder_api_key', null);
        } else {
            data_set($settings, 'integrations.vrs_responder_api_key', Crypt::encryptString(trim($key)));
        }

        if (data_get($settings, 'integrations') === []) {
            unset($settings['integrations']);
        }

        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * Platform-admin kill switches (independent of tenant suspend status).
     * Defaults false — feature allowed when not set.
     */
    public function outboundEpcisKilled(): bool
    {
        return $this->killSwitch(TenantKillSwitches::OUTBOUND_EPCIS);
    }

    public function inboundEpcisKilled(): bool
    {
        return $this->killSwitch(TenantKillSwitches::INBOUND_EPCIS);
    }

    public function sanctumApiKilled(): bool
    {
        return $this->killSwitch(TenantKillSwitches::SANCTUM_API);
    }

    public function wmsWebhooksKilled(): bool
    {
        return $this->killSwitch(TenantKillSwitches::WMS_WEBHOOKS);
    }

    public function setKillSwitch(string $key, bool $killed): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        if (! in_array($key, TenantKillSwitches::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown tenant kill switch [{$key}].");
        }

        $settings = $this->settingsBag();

        if (! $killed) {
            $switches = data_get($settings, 'kill_switches', []);
            if (is_array($switches)) {
                unset($switches[$key]);
                if ($switches === []) {
                    unset($settings['kill_switches']);
                } else {
                    $settings['kill_switches'] = $switches;
                }
            }
        } else {
            data_set($settings, 'kill_switches.'.$key, true);
        }

        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * @param  array<string, bool>  $switches
     */
    public function setKillSwitches(array $switches): self
    {
        foreach (TenantKillSwitches::KEYS as $key) {
            $this->setKillSwitch($key, (bool) ($switches[$key] ?? false));
        }

        return $this;
    }

    public function defaultShipFromSiteId(): ?int
    {
        $id = $this->setting('default_ship_from_site_id');

        return $id !== null && $id !== '' ? (int) $id : null;
    }

    public function setDefaultShipFromSiteId(?int $siteId): self
    {
        return $this->putSetting('default_ship_from_site_id', $siteId);
    }

    public function complianceContactName(): ?string
    {
        return $this->stringSetting('compliance_contact_name');
    }

    public function setComplianceContactName(?string $name): self
    {
        return $this->putSetting('compliance_contact_name', $this->nullableString($name));
    }

    public function complianceContactEmail(): ?string
    {
        return $this->stringSetting('compliance_contact_email');
    }

    public function setComplianceContactEmail(?string $email): self
    {
        return $this->putSetting('compliance_contact_email', $this->nullableString($email));
    }

    public function itContactName(): ?string
    {
        return $this->stringSetting('it_contact_name');
    }

    public function setItContactName(?string $name): self
    {
        return $this->putSetting('it_contact_name', $this->nullableString($name));
    }

    public function itContactEmail(): ?string
    {
        return $this->stringSetting('it_contact_email');
    }

    public function setItContactEmail(?string $email): self
    {
        return $this->putSetting('it_contact_email', $this->nullableString($email));
    }

    public function serializationContactName(): ?string
    {
        return $this->stringSetting('serialization_contact_name');
    }

    public function setSerializationContactName(?string $name): self
    {
        return $this->putSetting('serialization_contact_name', $this->nullableString($name));
    }

    public function serializationContactEmail(): ?string
    {
        return $this->stringSetting('serialization_contact_email');
    }

    public function setSerializationContactEmail(?string $email): self
    {
        return $this->putSetting('serialization_contact_email', $this->nullableString($email));
    }

    public function streetAddress(): ?string
    {
        return $this->stringSetting('street_address');
    }

    public function setStreetAddress(?string $value): self
    {
        return $this->putSetting('street_address', $this->nullableString($value));
    }

    public function streetAddress2(): ?string
    {
        return $this->stringSetting('street_address_2');
    }

    public function setStreetAddress2(?string $value): self
    {
        return $this->putSetting('street_address_2', $this->nullableString($value));
    }

    public function city(): ?string
    {
        return $this->stringSetting('city');
    }

    public function setCity(?string $value): self
    {
        return $this->putSetting('city', $this->nullableString($value));
    }

    public function state(): ?string
    {
        return $this->stringSetting('state');
    }

    public function setState(?string $value): self
    {
        return $this->putSetting('state', $this->nullableString($value));
    }

    public function zipcode(): ?string
    {
        return $this->stringSetting('zipcode');
    }

    public function setZipcode(?string $value): self
    {
        return $this->putSetting('zipcode', $this->nullableString($value));
    }

    public function countryCode(): ?string
    {
        return $this->stringSetting('country_code');
    }

    public function setCountryCode(?string $value): self
    {
        return $this->putSetting('country_code', $this->nullableString($value));
    }

    /**
     * @return array{
     *     street_address: ?string,
     *     street_address_2: ?string,
     *     city: ?string,
     *     state: ?string,
     *     zipcode: ?string,
     *     country_code: ?string,
     * }
     */
    public function organizationAddress(): array
    {
        return [
            'street_address' => $this->streetAddress(),
            'street_address_2' => $this->streetAddress2(),
            'city' => $this->city(),
            'state' => $this->state(),
            'zipcode' => $this->zipcode(),
            'country_code' => $this->countryCode(),
        ];
    }

    /**
     * True when street, city, state, and zip are all present (enough to copy onto a site).
     */
    public function hasOrganizationAddress(): bool
    {
        return filled($this->streetAddress())
            && filled($this->city())
            && filled($this->state())
            && filled($this->zipcode());
    }

    /**
     * @return array<string, mixed>
     */
    public function onboarding(): array
    {
        $value = $this->setting('onboarding');

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    public function setOnboarding(array $progress): self
    {
        return $this->putSetting('onboarding', $progress);
    }

    public function pairStatus(): ?string
    {
        $value = $this->setting('pair_status');

        return blank($value) ? null : (string) $value;
    }

    public function setPairStatus(?string $status): self
    {
        return $this->putSetting('pair_status', blank($status) ? null : trim($status));
    }

    public function complianceLastExportAt(): ?Carbon
    {
        $raw = data_get($this->settingsBag(), 'compliance.last_export_at');

        if (blank($raw)) {
            return null;
        }

        return Carbon::parse((string) $raw);
    }

    public function complianceLastExportPath(): ?string
    {
        $value = data_get($this->settingsBag(), 'compliance.last_export_path');

        return blank($value) ? null : (string) $value;
    }

    public function complianceLastExportAdminId(): ?int
    {
        $value = data_get($this->settingsBag(), 'compliance.last_export_admin_id');

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function hasRecentComplianceExport(int $days = 7): bool
    {
        $exportedAt = $this->complianceLastExportAt();

        return $exportedAt instanceof Carbon && $exportedAt->greaterThanOrEqualTo(now()->subDays($days));
    }

    public function recordComplianceExport(string $relativePath, int $adminId, ?Carbon $at = null): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();
        data_set($settings, 'compliance.last_export_at', ($at ?? now())->toIso8601String());
        data_set($settings, 'compliance.last_export_path', $relativePath);
        data_set($settings, 'compliance.last_export_admin_id', $adminId);
        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);
        $this->tenant->save();

        return $this;
    }

    public function onboardingDismissedAt(): ?Carbon
    {
        $raw = $this->setting('onboarding_dismissed_at');

        if (blank($raw)) {
            return null;
        }

        return Carbon::parse((string) $raw);
    }

    public function setOnboardingDismissedAt(?Carbon $at): self
    {
        return $this->putSetting(
            'onboarding_dismissed_at',
            $at?->toIso8601String(),
        );
    }

    public function outboundChoreographyDeferredAt(): ?Carbon
    {
        $raw = $this->setting('outbound_choreography_deferred_at');

        if (blank($raw)) {
            return null;
        }

        return Carbon::parse((string) $raw);
    }

    public function setOutboundChoreographyDeferredAt(?Carbon $at): self
    {
        return $this->putSetting(
            'outbound_choreography_deferred_at',
            $at?->toIso8601String(),
        );
    }

    /**
     * Soft-complete outbound onboarding when the tenant ships later.
     */
    public function acknowledgeOutboundDeferred(?Carbon $at = null): self
    {
        return $this->setOutboundChoreographyDeferredAt($at ?? now());
    }

    public function defaultReceiveSite(): ?Site
    {
        return $this->resolveSite($this->defaultReceiveSiteId());
    }

    public function defaultShipFromSite(): ?Site
    {
        return $this->resolveSite($this->defaultShipFromSiteId());
    }

    /**
     * Persist organization identity fields and contact / default-site settings.
     *
     * @param  array{
     *     gln?: string|null,
     *     company_prefix?: string|null,
     *     receiving_state?: string|null,
     *     street_address?: string|null,
     *     street_address_2?: string|null,
     *     city?: string|null,
     *     state?: string|null,
     *     zipcode?: string|null,
     *     country_code?: string|null,
     *     default_receive_site_id?: int|null,
     *     default_ship_from_site_id?: int|null,
     *     compliance_contact_name?: string|null,
     *     compliance_contact_email?: string|null,
     *     it_contact_name?: string|null,
     *     it_contact_email?: string|null,
     *     serialization_contact_name?: string|null,
     *     serialization_contact_email?: string|null,
     *     require_ti_for_scan_first?: bool|null,
     *     job_roles_enabled?: bool|null,
     *     client_print_bridge?: string|null,
     *     l3_enabled?: bool|null,
     *     l3_provider?: string|null,
     *     l3_endpoint_url?: string|null,
     *     l3_api_key?: string|null,
     *     wms_bridge_api_key?: string|null,
     *     dashboard_allow_user_customize?: bool|null,
     *     dashboard_defaults?: array<string, bool>|null,
     *     dashboard_allowed?: array<string, bool>|null,
     * }  $data
     */
    public function saveOrganization(array $data): void
    {
        if ($this->tenant === null) {
            return;
        }

        $previousCompanyPrefix = $this->companyPrefix();

        if (array_key_exists('gln', $data)) {
            $this->setGln($data['gln']);
        }

        if (array_key_exists('company_prefix', $data)) {
            $this->setCompanyPrefix(
                is_string($data['company_prefix']) || $data['company_prefix'] === null
                    ? $data['company_prefix']
                    : null,
            );
        }

        self::assertValidCompanyPrefix($this->companyPrefix(), $this->gln());

        app(AssertOrganizationSsccIdentity::class)->forTenant(
            $this->tenant,
            $this->gln(),
            $this->companyPrefix(),
        );

        if (array_key_exists('receiving_state', $data)) {
            $this->setReceivingState($data['receiving_state']);
        }

        foreach ([
            'street_address',
            'street_address_2',
            'city',
            'state',
            'zipcode',
            'country_code',
            'default_receive_site_id',
            'default_ship_from_site_id',
            'compliance_contact_name',
            'compliance_contact_email',
            'it_contact_name',
            'it_contact_email',
            'serialization_contact_name',
            'serialization_contact_email',
            'require_ti_for_scan_first',
            'job_roles_enabled',
            'client_print_bridge',
            'l3_enabled',
            'l3_provider',
            'l3_endpoint_url',
            'l3_api_key',
            'wms_bridge_api_key',
            'dashboard_allow_user_customize',
            'dashboard_defaults',
            'dashboard_allowed',
        ] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            match ($key) {
                'street_address' => $this->setStreetAddress(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'street_address_2' => $this->setStreetAddress2(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'city' => $this->setCity(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'state' => $this->setState(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'zipcode' => $this->setZipcode(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'country_code' => $this->setCountryCode(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'default_receive_site_id' => $this->setDefaultReceiveSiteId(
                    $data[$key] !== null && $data[$key] !== '' ? (int) $data[$key] : null,
                ),
                'default_ship_from_site_id' => $this->setDefaultShipFromSiteId(
                    $data[$key] !== null && $data[$key] !== '' ? (int) $data[$key] : null,
                ),
                'compliance_contact_name' => $this->setComplianceContactName(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'compliance_contact_email' => $this->setComplianceContactEmail(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'it_contact_name' => $this->setItContactName(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'it_contact_email' => $this->setItContactEmail(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'serialization_contact_name' => $this->setSerializationContactName(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'serialization_contact_email' => $this->setSerializationContactEmail(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'require_ti_for_scan_first' => $this->setRequireTiForScanFirst((bool) $data[$key]),
                'job_roles_enabled' => $this->setJobRolesEnabled((bool) $data[$key]),
                'client_print_bridge' => $this->setClientPrintBridge(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'l3_enabled' => $this->setL3Enabled((bool) $data[$key]),
                'l3_provider' => $this->setL3Provider(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'l3_endpoint_url' => $this->setL3EndpointUrl(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'l3_api_key' => $this->setL3ApiKey(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'wms_bridge_api_key' => $this->setWmsBridgeApiKey(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'dashboard_allow_user_customize' => $this->setDashboardAllowUserCustomize((bool) $data[$key]),
                'dashboard_defaults' => $this->setDashboardDefaults(
                    is_array($data[$key]) ? $data[$key] : [],
                ),
                'dashboard_allowed' => $this->setDashboardAllowed(
                    is_array($data[$key]) ? $data[$key] : [],
                ),
            };
        }

        $this->tenant->save();

        if ($this->companyPrefix() !== $previousCompanyPrefix) {
            $this->rederiveOrganizationSglns();
        }
    }

    /**
     * A new company prefix re-splits our own GLNs, so the SGLNs already stored for our
     * facilities describe locations under a prefix we no longer claim. They are rewritten
     * here rather than on next read, because SglnResolution lets a stored SGLN win over
     * the prefix — as it must, for the ones a partner stated.
     *
     * Only from inside the tenant's own database: the admin panel edits tenant identity
     * from the central context, where these tables are out of reach. `artisan
     * tracepharma:rederive-organization-sglns` closes that gap for ops.
     */
    private function rederiveOrganizationSglns(): void
    {
        $current = tenancy()->initialized ? tenant() : null;

        if (! $current instanceof Tenant || $current->getKey() !== $this->tenant?->getKey()) {
            return;
        }

        app(RederiveOrganizationSglns::class)->handle($this->companyPrefix());
    }

    private function resolveSite(?int $siteId): ?Site
    {
        if ($siteId === null || ! tenancy()->initialized) {
            return null;
        }

        return Site::query()->find($siteId);
    }

    private function stringSetting(string $key): ?string
    {
        $value = $this->setting($key);

        return blank($value) ? null : (string) $value;
    }

    private function setting(string $key): mixed
    {
        $settings = $this->settingsBag();

        return $settings[$key] ?? null;
    }

    private function putSetting(string $key, mixed $value): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();

        if ($value === null) {
            unset($settings[$key]);
        } else {
            $settings[$key] = $value;
        }

        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsBag(): array
    {
        $raw = $this->tenant?->getAttribute('settings');

        return is_array($raw) ? $raw : [];
    }

    /**
     * @return array<string, bool>
     */
    private function dashboardFlagMap(
        string $bagKey,
        bool $useCatalogHomeDefault = false,
        bool $missingDefault = false,
    ): array {
        $stored = data_get($this->settingsBag(), 'dashboard.'.$bagKey, []);
        $stored = is_array($stored) ? $stored : [];
        $map = [];

        foreach (DashboardWidgetCatalog::all() as $definition) {
            $key = $definition['key'];

            if (array_key_exists($key, $stored)) {
                $map[$key] = (bool) $stored[$key];

                continue;
            }

            $map[$key] = $useCatalogHomeDefault
                ? (bool) $definition['defaultOnHome']
                : $missingDefault;
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $flags
     * @return array<string, bool>
     */
    private function normalizeDashboardFlags(array $flags): array
    {
        $normalized = [];

        foreach (DashboardWidgetCatalog::keys() as $key) {
            if (array_key_exists($key, $flags)) {
                $normalized[$key] = (bool) $flags[$key];
            }
        }

        return $normalized;
    }

    private function putDashboardSetting(string $key, mixed $value): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();
        data_set($settings, 'dashboard.'.$key, $value);
        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    private function nullableString(?string $value): ?string
    {
        return blank($value) ? null : trim($value);
    }

    private function killSwitch(string $key): bool
    {
        return (bool) data_get($this->settingsBag(), 'kill_switches.'.$key, false);
    }
}
