<?php

namespace App\Support;

use App\Models\Tenant;

final class TenantAppUrl
{
    public static function forPath(string $path, ?Tenant $tenant = null): string
    {
        $path = '/'.ltrim($path, '/');
        $tenant ??= tenancy()->initialized ? tenant() : null;
        $domain = $tenant instanceof Tenant
            ? $tenant->domains()->orderBy('id')->value('domain')
            : null;

        if (! is_string($domain) || $domain === '') {
            return url($path);
        }

        return 'https://'.$domain.$path;
    }

    public static function exception(int|string $id, ?Tenant $tenant = null): string
    {
        return self::forPath('/exceptions/'.$id, $tenant);
    }
}
