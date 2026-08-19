<?php

namespace App\Support;

use App\Models\Tenant;

class TenantDatabaseName
{
    public static function fromDomain(string $domain): string
    {
        return 'tenant_'.strtolower(str_replace(['.', '-'], '_', $domain));
    }

    public static function fromTenant(Tenant $tenant): string
    {
        if (filled($tenant->tenancy_db_name)) {
            return (string) $tenant->tenancy_db_name;
        }

        $domain = $tenant->domains()->orderBy('id')->value('domain');

        if (is_string($domain) && $domain !== '') {
            return self::fromDomain($domain);
        }

        return 'tenant_'.str_replace('-', '_', (string) $tenant->getKey());
    }
}
