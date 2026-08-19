<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PairSiblingCentral
{
    public function enabled(): bool
    {
        $sibling = (string) config('tracepharma.pair_sibling_database', '');
        $default = (string) config('database.default');
        $current = (string) config("database.connections.{$default}.database");

        return $sibling !== '' && $sibling !== $current;
    }

    public function replicateIfAway(Tenant $tenant, string $environment): void
    {
        $environment = TenantHostname::assertPairEnvironment($environment);
        $current = TenantHostname::assertPairEnvironment(
            (string) config('tracepharma.tenant_environment', 'prod')
        );

        if ($environment === $current || ! $this->enabled()) {
            return;
        }

        $this->replicate($tenant);
    }

    public function replicate(Tenant $tenant): void
    {
        if (! $this->enabled()) {
            return;
        }

        $connection = $this->connection();

        if (! Schema::connection($connection->getName())->hasTable('tenants')
            || ! Schema::connection($connection->getName())->hasTable('domains')) {
            return;
        }

        $row = DB::table('tenants')->where('id', $tenant->id)->first();

        if ($row === null) {
            return;
        }

        $payload = (array) $row;
        unset($payload['id']);
        $connection->table('tenants')->updateOrInsert(['id' => $tenant->id], $payload);

        $tenant->loadMissing('domains');

        foreach ($tenant->domains as $domain) {
            $existing = $connection->table('domains')->where('domain', $domain->domain)->first();

            if ($existing !== null) {
                $connection->table('domains')->where('id', $existing->id)->update([
                    'tenant_id' => $tenant->id,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $connection->table('domains')->insert([
                'domain' => $domain->domain,
                'tenant_id' => $tenant->id,
                'created_at' => $domain->created_at ?? now(),
                'updated_at' => $domain->updated_at ?? now(),
            ]);
        }
    }

    public function forget(string $tenantId): void
    {
        if (! $this->enabled() || $tenantId === '') {
            return;
        }

        $connection = $this->connection();

        if (Schema::connection($connection->getName())->hasTable('domains')) {
            $connection->table('domains')->where('tenant_id', $tenantId)->delete();
        }

        if (Schema::connection($connection->getName())->hasTable('tenants')) {
            $connection->table('tenants')->where('id', $tenantId)->delete();
        }
    }

    /**
     * @return list<string>
     */
    public function syncAwayTenants(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $current = TenantHostname::assertPairEnvironment(
            (string) config('tracepharma.tenant_environment', 'prod')
        );
        $synced = [];

        foreach (Tenant::query()->get() as $tenant) {
            $environment = is_string($tenant->tenant_pair_environment)
                ? $tenant->tenant_pair_environment
                : null;

            if ($environment === null || $environment === $current) {
                continue;
            }

            $this->replicate($tenant->load('domains'));
            $synced[] = (string) $tenant->id;
        }

        return $synced;
    }

    private function connection(): Connection
    {
        return DB::connection('pair_sibling');
    }
}
