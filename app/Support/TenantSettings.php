<?php

namespace App\Support;

use App\Actions\MasterData\RederiveOrganizationSglns;
use App\Enums\ClientPrintBridge;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Dashboard\DashboardWidgetCatalog;
use App\Support\Epcis\EpcisSubscriptionUrl;
use App\Support\Gs1\AssertOrganizationSsccIdentity;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Tenancy\TenantKillSwitches;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

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

    public function receivingEdgeMode(): ?ReceivingEdgeMode
    {
        $value = data_get($this->settingsBag(), 'receiving.edge_mode');

        return is_string($value) ? ReceivingEdgeMode::tryFrom($value) : null;
    }

    public function setReceivingEdgeMode(?ReceivingEdgeMode $mode): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();

        if ($mode === null) {
            data_forget($settings, 'receiving.edge_mode');
        } else {
            data_set($settings, 'receiving.edge_mode', $mode->value);
        }

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

    /**
     * Pharmacy tenants: hide wholesaler-heavy floor nav (transfer, pack, ship order, etc.).
     * Default on for Pharmacy profile.
     */
    public function pharmacySimplifiedNavEnabled(): bool
    {
        return (bool) data_get($this->settingsBag(), 'access.pharmacy_simplified_nav', true);
    }

    public function setPharmacySimplifiedNavEnabled(bool $enabled): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();
        data_set($settings, 'access.pharmacy_simplified_nav', $enabled);
        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * Daily/weekly digest of Compliance Alert Center signals.
     * Default on — peers market real-time alerts; in-app center alone is not enough.
     */
    public function alertDigestEnabled(): bool
    {
        return (bool) data_get($this->settingsBag(), 'notifications.alert_digest_enabled', true);
    }

    public function setAlertDigestEnabled(bool $enabled): self
    {
        return $this->putNestedSetting('notifications.alert_digest_enabled', $enabled);
    }

    /**
     * @return 'daily'|'weekly'
     */
    public function alertDigestFrequency(): string
    {
        $value = data_get($this->settingsBag(), 'notifications.alert_digest_frequency', 'daily');

        return $value === 'weekly' ? 'weekly' : 'daily';
    }

    public function setAlertDigestFrequency(string $frequency): self
    {
        return $this->putNestedSetting(
            'notifications.alert_digest_frequency',
            $frequency === 'weekly' ? 'weekly' : 'daily',
        );
    }

    public function alertDigestLastSentAt(): ?Carbon
    {
        $raw = data_get($this->settingsBag(), 'notifications.alert_digest_last_sent_at');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setAlertDigestLastSentAt(Carbon|string|null $at): self
    {
        $value = $at instanceof Carbon
            ? $at->toIso8601String()
            : (is_string($at) && $at !== '' ? $at : null);

        return $this->putNestedSetting('notifications.alert_digest_last_sent_at', $value);
    }

    /**
     * After outbound ship complete, email the trading partner a signed customer portal link.
     */
    public function emailPortalOnShipEnabled(): bool
    {
        return (bool) data_get($this->settingsBag(), 'outbound.email_portal_on_ship', true);
    }

    public function setEmailPortalOnShipEnabled(bool $enabled): self
    {
        return $this->putNestedSetting('outbound.email_portal_on_ship', $enabled);
    }

    public function saveQuietly(): void
    {
        if ($this->tenant === null) {
            return;
        }

        $this->tenant->saveQuietly();
    }

    private function putNestedSetting(string $path, mixed $value): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();
        data_set($settings, $path, $value);
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

    /**
     * Guardian (Systech) lot-close inbound: archive raw DataFeed XML and
     * auto-project commissioning/aggregation into TracePharma. Manufacturer only.
     */
    public function l3GuardianLotCloseEnabled(): bool
    {
        return (bool) data_get($this->settingsBag(), 'l3.guardian_lot_close_enabled', false);
    }

    public function setL3GuardianLotCloseEnabled(bool $enabled): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $settings = $this->settingsBag();
        data_set($settings, 'l3.guardian_lot_close_enabled', $enabled);
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

        // Match ForwardCommissioningToL3 runtime guard (HTTPS + private/metadata deny).
        if ($normalized !== null) {
            EpcisSubscriptionUrl::assertSafeTargetUrl($normalized);
        }

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
        } catch (\Throwable $e) {
            // Fail closed: corrupt ciphertext must not POST to L3 without auth.
            throw new \RuntimeException('Tenant L3 API key could not be decrypted.', 0, $e);
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

    public function wmsReceiveConfirmUrl(): ?string
    {
        $value = data_get($this->settingsBag(), 'integrations.wms_receive_confirm_url');

        return blank($value) ? null : (string) $value;
    }

    public function setWmsReceiveConfirmUrl(?string $url): self
    {
        if ($this->tenant === null) {
            return $this;
        }

        $normalized = blank($url) ? null : trim($url);
        self::assertWmsReceiveConfirmUrlWithoutUserinfo($normalized);

        $settings = $this->settingsBag();
        data_set($settings, 'integrations.wms_receive_confirm_url', $normalized);

        if (data_get($settings, 'integrations') === []) {
            unset($settings['integrations']);
        }

        $this->tenant->setAttribute('settings', $settings === [] ? null : $settings);

        return $this;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertWmsReceiveConfirmUrlWithoutUserinfo(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $parsed = parse_url($url);

        if ($parsed === false || ! is_array($parsed)) {
            throw new \InvalidArgumentException('WMS receive-confirm URL is not valid.');
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \InvalidArgumentException(
                'WMS receive-confirm URL must not include credentials. Use the WMS bridge API key field instead.',
            );
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new \InvalidArgumentException('WMS receive-confirm URL must use HTTPS.');
        }

        $host = self::unwrapIpv4MappedAddress((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('WMS receive-confirm URL is not valid.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal' || $host === 'metadata.goog') {
            throw new \InvalidArgumentException('WMS receive-confirm URL must not target a private or metadata host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) {
                throw new \InvalidArgumentException('WMS receive-confirm URL must not target a private or metadata host.');
            }
        }
    }

    /**
     * Re-check the URL at connect time: resolve hostnames and deny loopback /
     * link-local / metadata addresses. RFC1918 remains allowed for on-prem WMS.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertWmsReceiveConfirmHostAtConnect(?string $url): void
    {
        self::assertWmsReceiveConfirmUrlWithoutUserinfo($url);

        if ($url === null || $url === '') {
            return;
        }

        $host = self::unwrapIpv4MappedAddress((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('WMS receive-confirm URL is not valid.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : self::resolveWmsHostAddresses($host);

        foreach ($addresses as $address) {
            if (self::isDeniedWmsResolvedAddress($address)) {
                throw new \InvalidArgumentException('WMS receive-confirm URL must not target a private or metadata host.');
            }
        }
    }

    public static function unwrapIpv4MappedAddress(string $host): string
    {
        $host = strtolower(trim($host, '[]'));
        if (str_starts_with($host, '::ffff:')) {
            $mapped = substr($host, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $mapped;
            }
        }

        return $host;
    }

    public static function isDeniedWmsResolvedAddress(string $ip): bool
    {
        $ip = self::unwrapIpv4MappedAddress($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = array_map('intval', explode('.', $ip));
            if (($octets[0] ?? null) === 127) {
                return true;
            }

            return ($octets[0] ?? null) === 169 && ($octets[1] ?? null) === 254;
        }

        if ($ip === '::1') {
            return true;
        }

        $packed = inet_pton($ip);
        $fe80 = inet_pton('fe80::');
        if ($packed !== false && $fe80 !== false) {
            return (ord($packed[0]) === 0xFE) && ((ord($packed[1]) & 0xC0) === 0x80);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function resolveWmsHostAddresses(string $host): array
    {
        $addresses = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = [...$addresses, ...$v4];
        }

        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $row) {
                    if (isset($row['ipv6'])) {
                        $addresses[] = (string) $row['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Pending HTTP client that resolves + pins the WMS URL (CURLOPT_RESOLVE).
     * Uses WMS deny rules: loopback / link-local / metadata blocked; RFC1918 allowed.
     *
     * @throws \InvalidArgumentException
     */
    public static function wmsPinnedHttpClient(string $url, int $timeoutSeconds = 30): PendingRequest
    {
        self::assertWmsReceiveConfirmHostAtConnect($url);

        $pending = Http::timeout($timeoutSeconds)->withoutRedirecting();

        $host = self::unwrapIpv4MappedAddress((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return $pending;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : self::resolveWmsHostAddresses($host);

        $safe = [];
        foreach ($addresses as $address) {
            if (self::isDeniedWmsResolvedAddress($address)) {
                throw new \InvalidArgumentException('WMS receive-confirm URL must not target a private or metadata host.');
            }
            $safe[] = $address;
        }

        if ($safe === []) {
            // Unresolvable hostnames (e.g. Http::fake .example suites) skip pin.
            return $pending;
        }

        $options = EpcisSubscriptionUrl::pinnedCurlOptions($url, $safe);
        if ($options !== []) {
            $pending = $pending->withOptions($options);
        }

        return $pending;
    }

    /**
     * Resolve + deny loopback/link-local/metadata for on-prem HTTP(S) egress (VRS HTTP, etc.).
     * RFC1918 remains allowed — stricter subscription HTTPS deny is NOT applied.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public static function assertAndResolveWmsStyleHost(string $url): array
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! is_array($parsed)) {
            throw new \InvalidArgumentException('URL is not valid.');
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('URL must use HTTP or HTTPS.');
        }

        $host = self::unwrapIpv4MappedAddress((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            throw new \InvalidArgumentException('URL host is not valid.');
        }

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === 'metadata.google.internal'
            || $host === 'metadata.goog') {
            throw new \InvalidArgumentException('URL must not target a private or metadata host.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : self::resolveWmsHostAddresses($host);

        if ($addresses === [] && filter_var($host, FILTER_VALIDATE_IP) === false) {
            if (app()->runningUnitTests()) {
                return [];
            }

            throw new \InvalidArgumentException('URL host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (self::isDeniedWmsResolvedAddress($address)) {
                throw new \InvalidArgumentException('URL must not target a private or metadata host.');
            }
        }

        return array_values($addresses);
    }

    /**
     * Pin DNS for on-prem HTTP(S) egress that allows RFC1918 (WMS-style deny).
     *
     * @throws \InvalidArgumentException
     */
    public static function wmsStylePinnedHttpClient(string $url, int $timeoutSeconds = 30): PendingRequest
    {
        $addresses = self::assertAndResolveWmsStyleHost($url);
        $pending = Http::timeout($timeoutSeconds)->withoutRedirecting();

        if ($addresses === []) {
            return $pending;
        }

        $options = EpcisSubscriptionUrl::pinnedCurlOptions($url, $addresses);
        if ($options !== []) {
            $pending = $pending->withOptions($options);
        }

        return $pending;
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

    /**
     * Tenant override for EPCIS 2.0 JSON-LD capture. Defaults true when the
     * platform flag TRACEPHARMA_EPCIS_ACCEPT_20 is on; set false to opt out.
     */
    public function epcisAccept20(): bool
    {
        $value = $this->setting('epcis.accept_20');

        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        return true;
    }

    public function setEpcisAccept20(?bool $enabled): self
    {
        if ($enabled === null) {
            return $this->putSetting('epcis.accept_20', null);
        }

        return $this->putSetting('epcis.accept_20', $enabled);
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
     *     receiving_edge_mode?: string|ReceivingEdgeMode|null,
     *     job_roles_enabled?: bool|null,
     *     client_print_bridge?: string|null,
     *     l3_enabled?: bool|null,
     *     l3_provider?: string|null,
     *     l3_endpoint_url?: string|null,
     *     l3_api_key?: string|null,
     *     l3_guardian_lot_close_enabled?: bool|null,
     *     wms_bridge_api_key?: string|null,
     *     wms_receive_confirm_url?: string|null,
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
            'receiving_edge_mode',
            'job_roles_enabled',
            'client_print_bridge',
            'l3_enabled',
            'l3_provider',
            'l3_endpoint_url',
            'l3_api_key',
            'l3_guardian_lot_close_enabled',
            'wms_bridge_api_key',
            'wms_receive_confirm_url',
            'dashboard_allow_user_customize',
            'dashboard_defaults',
            'dashboard_allowed',
            'pharmacy_simplified_nav',
            'alert_digest_enabled',
            'alert_digest_frequency',
            'email_portal_on_ship',
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
                'receiving_edge_mode' => $this->setReceivingEdgeMode($this->normalizeReceivingEdgeMode($data[$key])),
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
                'l3_guardian_lot_close_enabled' => $this->setL3GuardianLotCloseEnabled((bool) $data[$key]),
                'wms_bridge_api_key' => $this->setWmsBridgeApiKey(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'wms_receive_confirm_url' => $this->setWmsReceiveConfirmUrl(
                    is_string($data[$key]) || $data[$key] === null ? $data[$key] : null,
                ),
                'dashboard_allow_user_customize' => $this->setDashboardAllowUserCustomize((bool) $data[$key]),
                'dashboard_defaults' => $this->setDashboardDefaults(
                    is_array($data[$key]) ? $data[$key] : [],
                ),
                'dashboard_allowed' => $this->setDashboardAllowed(
                    is_array($data[$key]) ? $data[$key] : [],
                ),
                'pharmacy_simplified_nav' => $this->setPharmacySimplifiedNavEnabled((bool) $data[$key]),
                'alert_digest_enabled' => $this->setAlertDigestEnabled((bool) $data[$key]),
                'alert_digest_frequency' => $this->setAlertDigestFrequency(
                    is_string($data[$key]) ? $data[$key] : 'daily',
                ),
                'email_portal_on_ship' => $this->setEmailPortalOnShipEnabled((bool) $data[$key]),
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

    private function normalizeReceivingEdgeMode(mixed $value): ?ReceivingEdgeMode
    {
        if ($value instanceof ReceivingEdgeMode) {
            return $value;
        }

        return is_string($value) && $value !== '' ? ReceivingEdgeMode::tryFrom($value) : null;
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
