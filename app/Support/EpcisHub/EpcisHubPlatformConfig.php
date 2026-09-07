<?php

declare(strict_types=1);

namespace App\Support\EpcisHub;

use App\Support\PlatformSettings;
use App\Support\TenantHostname;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stancl\Tenancy\Database\Models\Domain;
use Throwable;

class EpcisHubPlatformConfig
{
    /** @var list<string> */
    public const ENVIRONMENTS = ['demo', 'stage', 'prod'];

    public const TOKEN_ROTATION_GRACE_HOURS = 24;

    public function environments(): array
    {
        return self::ENVIRONMENTS;
    }

    public function host(string $environment): string
    {
        $environment = $this->normalizeEnvironment($environment);
        $fromSettings = PlatformSettings::get("epcis_hub.{$environment}.host");

        if (is_string($fromSettings) && $fromSettings !== '') {
            return strtolower(trim($fromSettings));
        }

        $fromConfig = config("tracepharma.epcis_hub.{$environment}.host");

        if (is_string($fromConfig) && $fromConfig !== '') {
            return strtolower(trim($fromConfig));
        }

        return match ($environment) {
            'demo' => 'admin2.internal.vatengi.com',
            'prod' => 'prod.tracepharma.io',
            default => 'stage.tracepharma.io',
        };
    }

    public function environmentForHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        $testingHosts = config('tracepharma.epcis_hub.testing_hosts', []);

        if (is_array($testingHosts) && isset($testingHosts[$host])) {
            $mapped = $testingHosts[$host];

            return is_string($mapped) && in_array($mapped, self::ENVIRONMENTS, true)
                ? $mapped
                : null;
        }

        foreach (self::ENVIRONMENTS as $environment) {
            if ($this->host($environment) === $host) {
                return $environment;
            }
        }

        return null;
    }

    public function hubToken(string $environment): ?string
    {
        $environment = $this->normalizeEnvironment($environment);
        $fromSettings = PlatformSettings::get("epcis_hub.{$environment}.hub_token");

        if (is_string($fromSettings) && $fromSettings !== '') {
            return $fromSettings;
        }

        $fromEnvConfig = config("tracepharma.epcis_hub.{$environment}.hub_token");

        if (is_string($fromEnvConfig) && $fromEnvConfig !== '') {
            return $fromEnvConfig;
        }

        $legacy = config('tracepharma.epcis_hub.hub_token');

        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function enabledProviders(string $environment): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $fromSettings = PlatformSettings::get("epcis_hub.{$environment}.providers");

        if (is_string($fromSettings) && $fromSettings !== '') {
            $decoded = json_decode($fromSettings, true);

            if (is_array($decoded)) {
                return array_values(array_filter(
                    array_map(static fn ($p) => is_string($p) ? strtolower(trim($p)) : '', $decoded),
                    static fn (string $p): bool => $p !== '',
                ));
            }
        }

        $fromConfig = config('tracepharma.epcis_hub.providers', []);

        if (! is_array($fromConfig)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($p) => is_string($p) ? strtolower(trim($p)) : '', $fromConfig),
            static fn (string $p): bool => $p !== '',
        ));
    }

    public function hubUrl(string $environment, string $provider): string
    {
        $host = $this->host($environment);
        $provider = strtolower(trim($provider));

        return 'https://'.$host.'/api/webhooks/epcis/hub/'.$provider;
    }

    public function setHubToken(string $environment, ?string $token): void
    {
        $environment = $this->normalizeEnvironment($environment);
        $key = "epcis_hub.{$environment}.hub_token";

        if ($token === null || $token === '') {
            PlatformSettings::forget($key);

            return;
        }

        PlatformSettings::put($key, $token);
    }

    /**
     * Rotate the hub token: the current effective token (settings or config
     * fallback) stays accepted for a grace period so partners can cut over
     * without downtime. Returns the new token.
     */
    public function rotateHubToken(string $environment): string
    {
        $environment = $this->normalizeEnvironment($environment);

        $current = $this->hubToken($environment);
        $previousKey = "epcis_hub.{$environment}.hub_token_previous";
        $expiresKey = "epcis_hub.{$environment}.hub_token_previous_expires_at";

        if (is_string($current) && $current !== '') {
            PlatformSettings::put($previousKey, $current);
            PlatformSettings::put(
                $expiresKey,
                now()->addHours(self::TOKEN_ROTATION_GRACE_HOURS)->toIso8601String(),
            );
        } else {
            PlatformSettings::forget($previousKey);
            PlatformSettings::forget($expiresKey);
        }

        $token = Str::random(64);
        PlatformSettings::put("epcis_hub.{$environment}.hub_token", $token);

        return $token;
    }

    /**
     * The previous hub token while it is still inside the rotation grace window.
     */
    public function previousHubToken(string $environment): ?string
    {
        $expiresAt = $this->previousHubTokenExpiresAt($environment);

        if ($expiresAt === null || $expiresAt->isPast()) {
            return null;
        }

        $environment = $this->normalizeEnvironment($environment);
        $previous = PlatformSettings::get("epcis_hub.{$environment}.hub_token_previous");

        return is_string($previous) && $previous !== '' ? $previous : null;
    }

    public function previousHubTokenExpiresAt(string $environment): ?CarbonImmutable
    {
        $environment = $this->normalizeEnvironment($environment);
        $raw = PlatformSettings::get("epcis_hub.{$environment}.hub_token_previous_expires_at");

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The hub environment this deployment serves, derived from the app's own host.
     */
    public function currentEnvironment(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $environment = is_string($host) ? $this->environmentForHost($host) : null;

        return $environment ?? 'demo';
    }

    /**
     * @param  list<string>|null  $providers
     */
    public function setProviders(string $environment, ?array $providers): void
    {
        $environment = $this->normalizeEnvironment($environment);
        $key = "epcis_hub.{$environment}.providers";

        if ($providers === null) {
            PlatformSettings::forget($key);

            return;
        }

        $normalized = array_values(array_filter(
            array_map(static fn ($p) => is_string($p) ? strtolower(trim($p)) : '', $providers),
            static fn (string $p): bool => $p !== '',
        ));

        PlatformSettings::put($key, json_encode(array_values($normalized)));
    }

    public function setHost(string $environment, ?string $host): void
    {
        $environment = $this->normalizeEnvironment($environment);
        $key = "epcis_hub.{$environment}.host";

        if ($host === null || trim($host) === '') {
            PlatformSettings::forget($key);

            return;
        }

        self::assertHubHostAllowed($host);

        PlatformSettings::put($key, strtolower(trim($host)));
    }

    /**
     * Outbound collaboration edge URL for UniTrace/Systech (tenant transmitter egress).
     */
    public function outboundUrl(string $environment, string $provider): ?string
    {
        $environment = $this->normalizeEnvironment($environment);
        $provider = $this->normalizeHubProvider($provider);
        $fromSettings = PlatformSettings::get("epcis_hub.{$environment}.outbound_url_{$provider}");

        if (is_string($fromSettings) && trim($fromSettings) !== '') {
            return trim($fromSettings);
        }

        return null;
    }

    public function outboundToken(string $environment, string $provider): ?string
    {
        $environment = $this->normalizeEnvironment($environment);
        $provider = $this->normalizeHubProvider($provider);
        $fromSettings = PlatformSettings::get("epcis_hub.{$environment}.outbound_token_{$provider}");

        if (is_string($fromSettings) && $fromSettings !== '') {
            return $fromSettings;
        }

        return null;
    }

    public function hasOutboundEdge(string $environment, string $provider): bool
    {
        $url = $this->outboundUrl($environment, $provider);

        return is_string($url) && $url !== '';
    }

    public function setOutboundUrl(string $environment, string $provider, ?string $url): void
    {
        $environment = $this->normalizeEnvironment($environment);
        $provider = $this->normalizeHubProvider($provider);
        $key = "epcis_hub.{$environment}.outbound_url_{$provider}";

        if ($url === null || trim($url) === '') {
            PlatformSettings::forget($key);

            return;
        }

        $url = trim($url);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($url), 'https://')) {
            throw new InvalidArgumentException('Outbound hub URL must be an https:// URL.');
        }

        PlatformSettings::put($key, $url);
    }

    public function setOutboundToken(string $environment, string $provider, ?string $token): void
    {
        $environment = $this->normalizeEnvironment($environment);
        $provider = $this->normalizeHubProvider($provider);
        $key = "epcis_hub.{$environment}.outbound_token_{$provider}";

        if ($token === null || $token === '') {
            PlatformSettings::forget($key);

            return;
        }

        PlatformSettings::put($key, $token);
    }

    private function normalizeHubProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        if (! in_array($provider, ['systech', 'unitrace'], true)) {
            throw new InvalidArgumentException("Unsupported hub provider [{$provider}].");
        }

        return $provider;
    }

    public static function assertHubHostAllowed(?string $host): void
    {
        if ($host === null || trim($host) === '') {
            return;
        }

        $host = strtolower(trim($host));

        if (Domain::query()->where('domain', $host)->exists()) {
            throw new InvalidArgumentException(
                "The host {$host} is already assigned to a tenant. Hub host overrides must not overlap tenant domains.",
            );
        }

        if (TenantHostname::looksLikePairHost($host)) {
            throw new InvalidArgumentException(
                'This host matches the tenant pair hostname pattern ('.TenantHostname::pairHint().'). Hub host overrides must not overlap tenant domains.',
            );
        }

        if (TenantHostname::isReservedHost($host)) {
            throw new InvalidArgumentException(
                "The host {$host} is reserved for central platform use. Hub host overrides must not use central, admin, or marketing domains.",
            );
        }
    }

    private function normalizeEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));

        if (! in_array($environment, self::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException("Unsupported EPCIS hub environment [{$environment}].");
        }

        return $environment;
    }
}
