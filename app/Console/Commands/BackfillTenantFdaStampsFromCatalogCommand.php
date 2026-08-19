<?php

namespace App\Console\Commands;

use App\Actions\MasterData\BackfillTenantFdaStampsFromCatalog;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class BackfillTenantFdaStampsFromCatalogCommand extends Command
{
    protected $signature = 'tracepharma:backfill-tenant-fda-stamps-from-catalog
        {--tenants=* : Tenant id(s) to backfill; default all}';

    protected $description = 'Copy leftover catalog-row FDA ids onto tenant stamps, then drop tenant catalog_*_id columns';

    public function handle(BackfillTenantFdaStampsFromCatalog $backfill): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            /** @var array{partners: int, sites: int, products: int, licenses: int} $counts */
            $counts = $tenant->run(fn (): array => $backfill->handle());

            $this->line(
                "[{$tenant->id}] {$tenant->name}: partners={$counts['partners']}"
                .", sites={$counts['sites']}"
                .", products={$counts['products']}"
                .", licenses={$counts['licenses']}",
            );
        }

        $this->info('Done. Tenant catalog_*_id columns dropped; catalog tables were not dropped.');

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
