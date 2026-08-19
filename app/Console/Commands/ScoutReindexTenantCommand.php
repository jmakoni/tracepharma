<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Scout\TenantScoutCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ScoutReindexTenantCommand extends Command
{
    protected $signature = 'tracepharma:scout-reindex
        {--tenant= : Tenant id (required unless already in tenancy)}
        {--model=all : products, partners, documents, events, or all}
        {--flush : Flush each index before importing}';

    protected $description = 'Import tenant Product, TradingPartner, EpcisDocument, and EpcisEvent rows into Scout (per-tenant index names)';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        if ($tenant === null) {
            return self::FAILURE;
        }

        $alreadyOnTenant = tenancy()->initialized
            && tenant() instanceof Tenant
            && tenant()->getKey() === $tenant->getKey();

        if (! $alreadyOnTenant) {
            tenancy()->initialize($tenant);
        }

        try {
            $models = $this->resolveModels();
            if ($models === []) {
                $this->error('Unknown --model value. Use products, partners, documents, events, or all.');

                return self::FAILURE;
            }

            $this->info("Tenant: {$tenant->id} ({$tenant->name})");

            foreach ($models as $label => $class) {
                if ((bool) $this->option('flush')) {
                    $this->line("Flushing {$label}…");
                    $flushExit = Artisan::call('scout:flush', ['model' => $class]);
                    $this->output->write(Artisan::output());

                    if ($flushExit !== self::SUCCESS) {
                        $this->error("Flush failed for {$label}.");

                        return self::FAILURE;
                    }
                }

                $this->line("Importing {$label}…");
                $importExit = Artisan::call('scout:import', ['model' => $class]);
                $this->output->write(Artisan::output());

                if ($importExit !== self::SUCCESS) {
                    $this->error("Import failed for {$label}.");

                    return self::FAILURE;
                }
            }

            $this->info('Tenant Scout reindex complete.');

            return self::SUCCESS;
        } finally {
            if (! $alreadyOnTenant) {
                tenancy()->end();
            }
        }
    }

    /**
     * @return array<string, class-string>|array{}
     */
    private function resolveModels(): array
    {
        return TenantScoutCatalog::resolveModels((string) $this->option('model'));
    }

    private function resolveTenant(): ?Tenant
    {
        $tenantId = $this->option('tenant');

        if (filled($tenantId)) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                $this->error("Tenant [{$tenantId}] was not found.");
            }

            return $tenant;
        }

        if (tenancy()->initialized && tenant() instanceof Tenant) {
            return tenant();
        }

        $this->error('Pass --tenant= or run inside an initialized tenancy context.');

        return null;
    }
}
