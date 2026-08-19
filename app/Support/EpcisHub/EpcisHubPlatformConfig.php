<?php

declare(strict_types=1);

namespace App\Support\EpcisHub;

use App\Support\PlatformSettings;
use App\Support\TenantHostname;
use InvalidArgumentException;
use Stancl\Tenancy\Database\Models\Domain;

class EpcisHubPlatformConfig
{
    /** @var list<string> */
    public const ENVIRONMENTS = ['demo', 'stage', 'prod'];

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
            throw new \InvalidArgumentException("Unsupported EPCIS hub environment [{$environment}].");
        }

        return $environment;
    }
}
