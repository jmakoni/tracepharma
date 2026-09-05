<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlatformSetting;
use App\Support\Dashboard\AdminDashboardWidgetCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class PlatformSettings
{
    private const CACHE_PREFIX = 'platform_setting:';

    public static function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::rememberForever(self::cacheKey($key), function () use ($key): ?string {
            $row = PlatformSetting::query()->where('key', $key)->first();

            return $row?->value;
        });

        if ($cached === null) {
            return $default;
        }

        if (self::isEncryptedKey($key)) {
            try {
                return Crypt::decryptString($cached);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $cached;
    }

    public static function put(string $key, ?string $value): void
    {
        $stored = $value;

        if ($value !== null && self::isEncryptedKey($key)) {
            $stored = Crypt::encryptString($value);
        }

        PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored],
        );

        Cache::forget(self::cacheKey($key));
    }

    public static function forget(string $key): void
    {
        PlatformSetting::query()->where('key', $key)->delete();
        Cache::forget(self::cacheKey($key));
    }

    public static function adminDashboardAllowUserCustomize(): bool
    {
        return (bool) data_get(self::adminDashboardBag(), 'allow_user_customize', true);
    }

    public static function setAdminDashboardAllowUserCustomize(bool $allow): void
    {
        $bag = self::adminDashboardBag();
        $bag['allow_user_customize'] = $allow;
        self::putAdminDashboardBag($bag);
    }

    /**
     * @return array<string, bool>
     */
    public static function adminDashboardDefaults(): array
    {
        return self::adminDashboardFlagMap('defaults', useCatalogHomeDefault: true);
    }

    /**
     * @param  array<string, bool>  $defaults
     */
    public static function setAdminDashboardDefaults(array $defaults): void
    {
        $bag = self::adminDashboardBag();
        $bag['defaults'] = self::normalizeAdminDashboardFlags($defaults);
        self::putAdminDashboardBag($bag);
    }

    /**
     * Missing lean keys stay allowed. Analytics stay allowed until an admin disables them.
     *
     * @return array<string, bool>
     */
    public static function adminDashboardAllowed(): array
    {
        return self::adminDashboardFlagMap('allowed', missingDefault: true);
    }

    /**
     * @param  array<string, bool>  $allowed
     */
    public static function setAdminDashboardAllowed(array $allowed): void
    {
        $bag = self::adminDashboardBag();
        $bag['allowed'] = self::normalizeAdminDashboardFlags($allowed);
        self::putAdminDashboardBag($bag);
    }

    /**
     * @return array<string, mixed>
     */
    private static function adminDashboardBag(): array
    {
        $raw = self::get('admin_dashboard');

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private static function putAdminDashboardBag(array $bag): void
    {
        self::put('admin_dashboard', json_encode($bag, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, bool>
     */
    private static function adminDashboardFlagMap(
        string $bagKey,
        bool $useCatalogHomeDefault = false,
        bool $missingDefault = false,
    ): array {
        $stored = data_get(self::adminDashboardBag(), $bagKey, []);
        $stored = is_array($stored) ? $stored : [];
        $map = [];

        foreach (AdminDashboardWidgetCatalog::all() as $definition) {
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
    private static function normalizeAdminDashboardFlags(array $flags): array
    {
        $normalized = [];

        foreach (AdminDashboardWidgetCatalog::keys() as $key) {
            if (array_key_exists($key, $flags)) {
                $normalized[$key] = (bool) $flags[$key];
            }
        }

        return $normalized;
    }

    public static function ssoAdminEnabled(): bool
    {
        return filter_var(self::get('sso.admin.enabled', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function ssoAdminOnly(): bool
    {
        return filter_var(self::get('sso.admin.sso_only', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    public static function ssoAdminProvider(): string
    {
        return (string) self::get('sso.admin.provider', 'entra');
    }

    public static function ssoAdminIssuer(): string
    {
        return (string) self::get('sso.admin.issuer', '');
    }

    public static function ssoAdminClientId(): string
    {
        return (string) self::get('sso.admin.client_id', '');
    }

    public static function ssoAdminClientSecret(): string
    {
        return (string) self::get('sso.admin.client_secret', '');
    }

    public static function ssoAdminEntraTenantId(): ?string
    {
        $value = self::get('sso.admin.entra_tenant_id');

        return filled($value) ? (string) $value : null;
    }

    /**
     * @param  array{
     *     enabled?: bool,
     *     sso_only?: bool,
     *     provider?: string,
     *     issuer?: string,
     *     client_id?: string,
     *     client_secret?: string|null,
     *     entra_tenant_id?: string|null
     * }  $data
     */
    public static function saveSsoAdminConfig(array $data): void
    {
        if (array_key_exists('enabled', $data)) {
            self::put('sso.admin.enabled', ($data['enabled'] ?? false) ? '1' : '0');
        }
        if (array_key_exists('sso_only', $data)) {
            self::put('sso.admin.sso_only', ($data['sso_only'] ?? false) ? '1' : '0');
        }
        if (array_key_exists('provider', $data)) {
            self::put('sso.admin.provider', (string) ($data['provider'] ?? 'entra'));
        }
        if (array_key_exists('issuer', $data)) {
            self::put('sso.admin.issuer', trim((string) ($data['issuer'] ?? '')));
        }
        if (array_key_exists('client_id', $data)) {
            self::put('sso.admin.client_id', trim((string) ($data['client_id'] ?? '')));
        }
        if (array_key_exists('client_secret', $data) && filled($data['client_secret'])) {
            self::put('sso.admin.client_secret', trim((string) $data['client_secret']));
        }
        if (array_key_exists('entra_tenant_id', $data)) {
            $entra = $data['entra_tenant_id'];
            if (filled($entra)) {
                self::put('sso.admin.entra_tenant_id', trim((string) $entra));
            } else {
                self::forget('sso.admin.entra_tenant_id');
            }
        }
    }

    private static function isEncryptedKey(string $key): bool
    {
        return str_ends_with($key, 'hub_token') || str_ends_with($key, 'client_secret');
    }

    private static function cacheKey(string $key): string
    {
        return self::CACHE_PREFIX.$key;
    }
}
