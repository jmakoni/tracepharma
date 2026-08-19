<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\EnsureTenantStorageDirectories;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class EnsureTenantStorageCommand extends Command
{
    protected $signature = 'tracepharma:ensure-tenant-storage
        {--tenants=* : Tenant id(s) to repair; default all}';

    protected $description = 'Create/repair tenant storage directories for Livewire uploads and local disks';

    public function handle(EnsureTenantStorageDirectories $ensure): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $result = $ensure->handle($tenant);
            $created = count($result['created']);
            $this->line("[{$tenant->getTenantKey()}] {$result['path']} (created {$created} dirs)");
        }

        $this->info('Done. tenants='.$tenants->count());

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function resolveTenants(): Collection
    {
        /** @var list<string> $tenantIds */
        $tenantIds = $this->option('tenants');

        if ($tenantIds !== []) {
            return Tenant::query()->whereIn('id', $tenantIds)->orderBy('id')->get();
        }

        return Tenant::query()->orderBy('id')->get();
    }
}
