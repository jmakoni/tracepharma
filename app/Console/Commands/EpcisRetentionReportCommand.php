<?php

namespace App\Console\Commands;

use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Console\Command;

class EpcisRetentionReportCommand extends Command
{
    protected $signature = 'tracepharma:epcis-retention-report
        {--tenant= : Tenant id; defaults to the current tenancy context}';

    protected $description = 'Report EPCIS documents older than configured retention_years (no deletes)';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        if ($tenant === null) {
            return self::FAILURE;
        }

        $years = (int) config('tracepharma.epcis.retention_years', 6);
        $cutoff = now()->subYears($years);

        $alreadyOnTenant = tenancy()->initialized
            && tenant() instanceof Tenant
            && tenant()->getKey() === $tenant->getKey();

        if (! $alreadyOnTenant) {
            tenancy()->initialize($tenant);
        }

        try {
            $count = EpcisDocument::query()
                ->where('received_at', '<', $cutoff)
                ->count();

            $this->info("Tenant: {$tenant->id} ({$tenant->name})");
            $this->info("Retention years: {$years}");
            $this->info('Cutoff (received_at <): '.$cutoff->toDateTimeString());
            $this->info("Documents older than retention: {$count}");
            $this->comment('Report only — no documents were deleted.');

            return self::SUCCESS;
        } finally {
            if (! $alreadyOnTenant) {
                tenancy()->end();
            }
        }
    }

    private function resolveTenant(): ?Tenant
    {
        $tenantId = $this->option('tenant');

        if (filled($tenantId)) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                $this->error("Tenant not found: {$tenantId}");

                return null;
            }

            return $tenant;
        }

        if (tenancy()->initialized && tenant() instanceof Tenant) {
            return tenant();
        }

        $this->error('No tenant context. Pass --tenant= or run inside an initialized tenancy.');

        return null;
    }
}
