<?php

namespace App\Console\Commands;

use App\Actions\MasterData\CopyFdaWddLicensesToTenantSite;
use App\Jobs\SyncTenantAtpLicensesFromFda;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class SyncTenantAtpFromFdaCommand extends Command
{
    protected $signature = 'tracepharma:sync-tenant-atp-from-fda
        {--tenants=* : Tenant id(s) to sync; default all active tenants}';

    protected $description = 'Refresh tenant ATP licenses from FDA WDD licenses for facility-linked partner sites';

    public function handle(CopyFdaWddLicensesToTenantSite $copier): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return SymfonyCommand::SUCCESS;
        }

        $totals = [
            'tenants' => 0,
            'sites' => 0,
            'licenses' => 0,
            'pruned' => 0,
            'failed' => 0,
        ];

        foreach ($tenants as $tenant) {
            try {
                $counts = (new SyncTenantAtpLicensesFromFda($tenant))->handle($copier);

                $totals['tenants']++;
                $totals['sites'] += $counts['sites'];
                $totals['licenses'] += $counts['licenses'];
                $totals['pruned'] += $counts['pruned'];

                $this->line(
                    "[{$tenant->id}] {$tenant->name}: sites={$counts['sites']}"
                    .", licenses={$counts['licenses']}, deactivated={$counts['pruned']}",
                );
            } catch (Throwable $exception) {
                $totals['failed']++;
                $this->error("[{$tenant->id}] {$tenant->name}: {$exception->getMessage()}");
                Log::error('Tenant ATP FDA sync failed', [
                    'tenant_id' => $tenant->id,
                    'exception' => $exception,
                ]);
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        $this->info(
            "Done. tenants={$totals['tenants']}, sites={$totals['sites']}"
            .", licenses={$totals['licenses']}, deactivated={$totals['pruned']}"
            .", failed={$totals['failed']}",
        );

        return $totals['failed'] > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
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

        return Tenant::query()->where('status', 'active')->orderBy('id')->get();
    }
}
