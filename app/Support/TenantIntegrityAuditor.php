<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class TenantIntegrityAuditor
{
    /**
     * @return array{
     *     healthy: bool,
     *     detached_tenants: list<array{id: string, name: string, db_name: string, domains: list<string>}>,
     *     tenants_without_domains: list<array{id: string, name: string}>,
     *     orphan_databases: list<string>,
     * }
     */
    public function audit(): array
    {
        $tenants = Tenant::query()->with('domains')->get();

        $detachedTenants = [];
        $tenantsWithoutDomains = [];
        $claimedDatabases = [];

        foreach ($tenants as $tenant) {
            $domains = $tenant->domains->pluck('domain')->all();

            if ($domains === []) {
                $tenantsWithoutDomains[] = [
                    'id' => $tenant->id,
                    'name' => (string) $tenant->name,
                ];

                continue;
            }

            $dbName = TenantDatabaseName::fromTenant($tenant);
            $claimedDatabases[$dbName] = true;

            if (! $this->databaseExists($dbName)) {
                $detachedTenants[] = [
                    'id' => $tenant->id,
                    'name' => (string) $tenant->name,
                    'db_name' => $dbName,
                    'domains' => $domains,
                ];
            }
        }

        $orphanDatabases = [];

        foreach ($this->listTenantDatabases() as $database) {
            if (! isset($claimedDatabases[$database])) {
                $orphanDatabases[] = $database;
            }
        }

        return [
            'healthy' => $detachedTenants === [] && $tenantsWithoutDomains === [],
            'detached_tenants' => $detachedTenants,
            'tenants_without_domains' => $tenantsWithoutDomains,
            'orphan_databases' => $orphanDatabases,
        ];
    }

    public function databaseExists(string $database): bool
    {
        $connection = config('tenancy.database.central_connection');

        return (bool) DB::connection($connection)->select(
            'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$database],
        );
    }

    /**
     * @return list<string>
     */
    public function listTenantDatabases(): array
    {
        $connection = config('tenancy.database.central_connection');
        $prefix = (string) config('tenancy.database.prefix', 'tenant');

        $rows = DB::connection($connection)->select(
            'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE ?',
            [$prefix.'%'],
        );

        return array_values(array_map(
            fn (object $row): string => (string) $row->SCHEMA_NAME,
            $rows,
        ));
    }
}
