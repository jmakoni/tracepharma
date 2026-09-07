<?php

declare(strict_types=1);

namespace App\Support\Integrations\Concerns;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\PlatformSettings;
use InvalidArgumentException;

/**
 * Shared storage plumbing for platform-owned edge credentials (AS2 station,
 * SFTP drop). Values live in central platform_settings under
 * "{prefix}.{environment}.{key}"; secret keys are encrypted by PlatformSettings.
 */
trait ManagesPlatformEdgeSettings
{
    abstract protected function prefix(): string;

    /**
     * Present + filled values are stored, present + empty clears the key,
     * absent keys are left untouched (write-only secret fields stay blank).
     *
     * @param  array<string, string|null>  $values
     * @param  list<string>  $allowedKeys
     */
    protected function saveValues(string $environment, array $values, array $allowedKeys): void
    {
        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];
            $this->put($environment, $key, is_string($value) ? $value : null);
        }
    }

    protected function value(string $environment, string $key): ?string
    {
        $raw = PlatformSettings::get($this->settingsKey($environment, $key));

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }

    protected function put(string $environment, string $key, ?string $value): void
    {
        $settingsKey = $this->settingsKey($environment, $key);

        if ($value === null || trim($value) === '') {
            PlatformSettings::forget($settingsKey);

            return;
        }

        PlatformSettings::put($settingsKey, trim($value));
    }

    protected function settingsKey(string $environment, string $key): string
    {
        return $this->prefix().'.'.$this->normalizeEnvironment($environment).'.'.$key;
    }

    protected function normalizeEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));

        if (! in_array($environment, EpcisHubPlatformConfig::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException("Unsupported EPCIS hub environment [{$environment}].");
        }

        return $environment;
    }
}
