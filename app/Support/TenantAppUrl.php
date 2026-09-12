<?php

namespace App\Support;

use App\Models\Tenant;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Database\Models\Domain;

final class TenantAppUrl
{
    public static function forPath(string $path, ?Tenant $tenant = null, ?string $tenantId = null): string
    {
        $path = '/'.ltrim($path, '/');
        $domain = self::resolveDomain($tenant, $tenantId);

        if ($domain === null) {
            return url($path);
        }

        return 'https://'.$domain.$path;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function temporarySignedRoute(
        string $name,
        DateTimeInterface|DateInterval|int $expiration,
        array $parameters = [],
        ?Tenant $tenant = null,
        ?string $tenantId = null,
    ): string {
        $domain = self::resolveDomain($tenant, $tenantId);
        $previousRoot = config('app.url');

        if ($domain !== null) {
            URL::forceRootUrl('https://'.$domain);
            URL::forceScheme('https');
        }

        try {
            return URL::temporarySignedRoute($name, $expiration, $parameters);
        } finally {
            URL::forceRootUrl($previousRoot);
            if ($domain !== null) {
                URL::forceScheme(null);
            }
        }
    }

    public static function resolveDomain(?Tenant $tenant = null, ?string $tenantId = null): ?string
    {
        if ($tenant === null && $tenantId !== null) {
            $tenant = Tenant::query()->find($tenantId);
        }

        $tenant ??= tenancy()->initialized ? tenant() : null;

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $domain = Domain::query()
            ->where('tenant_id', $tenant->getTenantKey())
            ->orderBy('id')
            ->value('domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    public static function exception(int|string $id, ?Tenant $tenant = null): string
    {
        return self::forPath('/exceptions/'.$id, $tenant);
    }
}
