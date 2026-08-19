<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Scout\TenantScoutCatalog;
use App\Support\Scout\TenantScoutIndexSync;
use Illuminate\Console\Command;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class ScoutSyncIndexSettingsCommand extends Command
{
    protected $signature = 'tracepharma:scout-sync-index-settings
        {--tenant= : Tenant id}
        {--all-tenants : Sync settings for every tenant}';

    protected $description = 'Sync Meilisearch index settings for tenant Scout indexes (per-tenant index names)';

    public function handle(EngineManager $manager, TenantScoutIndexSync $sync): int
    {
        if (! TenantScoutCatalog::usesRemoteIndexSettings()) {
            $driver = (string) config('scout.driver', 'collection');
            $this->info("Scout driver [{$driver}] does not use remote index settings; skipping.");

            return SymfonyCommand::SUCCESS;
        }

        $engine = $manager->engine();

        if (! $engine instanceof UpdatesIndexSettings) {
            $this->error('The configured Scout engine does not support updating index settings.');

            return SymfonyCommand::FAILURE;
        }

        $tenants = $this->resolveTenants();
        if ($tenants === null) {
            return SymfonyCommand::FAILURE;
        }

        $failures = 0;

        foreach ($tenants as $tenant) {
            $this->info("Tenant: {$tenant->id} ({$tenant->name})");

            try {
                $indexes = $sync->syncTenant($tenant, engine: $engine);

                foreach ($indexes as $indexName) {
                    $this->line("  Settings synced for [{$indexName}]");
                }
            } catch (\Throwable $exception) {
                $failures++;
                $this->error("  Failed for tenant {$tenant->id}: {$exception->getMessage()}");
            }
        }

        if ($failures > 0) {
            $this->error("Finished with {$failures} tenant failure(s).");

            return SymfonyCommand::FAILURE;
        }

        $this->info('Tenant Scout index settings sync complete.');

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @return \Generator<int, Tenant>|null
     */
    private function resolveTenants(): ?\Generator
    {
        if ((bool) $this->option('all-tenants')) {
            if (filled($this->option('tenant'))) {
                $this->error('Pass either --tenant= or --all-tenants, not both.');

                return null;
            }

            return Tenant::query()->orderBy('id')->cursor();
        }

        $tenantId = $this->option('tenant');

        if (! filled($tenantId)) {
            $this->error('Pass --tenant= or --all-tenants.');

            return null;
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            $this->error("Tenant [{$tenantId}] was not found.");

            return null;
        }

        return (function () use ($tenant): \Generator {
            yield $tenant;
        })();
    }
}
