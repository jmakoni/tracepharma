<?php

namespace App\Console\Commands;

use App\Actions\MasterData\RederiveOrganizationSglns;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use App\Support\TenantSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bring organization facility SGLNs back under the tenant's GS1 Company Prefix.
 *
 * Organization Settings already does this when the prefix changes there. This is for
 * the prefix changes that happened anywhere else: the admin panel, which edits tenant
 * identity from the central database where these tables are out of reach, a direct
 * repair, or a tenant whose rows predate the stored SGLN column.
 */
class RederiveOrganizationSglnsCommand extends Command
{
    protected $signature = 'tracepharma:rederive-organization-sglns
        {--tenants=* : Tenant id(s) to re-derive; default all}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Re-derive organization facility and location device SGLNs under the tenant GS1 Company Prefix';

    public function handle(RederiveOrganizationSglns $rederive): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totals = ['sites' => 0, 'location_devices' => 0];

        foreach ($tenants as $tenant) {
            $prefix = TenantSettings::forTenant($tenant)->companyPrefix();

            if ($prefix === null) {
                $this->warn(
                    "[{$tenant->id}] {$tenant->name}: no GS1 Company Prefix configured — "
                    .'SGLNs that no longer encode their own location will be cleared, and none derived.',
                );
            }

            /** @var array{sites: int, location_devices: int} $counts */
            $counts = TenantRunner::run($tenant, fn (): array => $rederive->handle($prefix, $dryRun));

            $totals['sites'] += $counts['sites'];
            $totals['location_devices'] += $counts['location_devices'];

            $this->line(
                "[{$tenant->id}] {$tenant->name}: sites={$counts['sites']}"
                .", location_devices={$counts['location_devices']}",
            );
        }

        $this->info(
            ($dryRun ? 'Dry run. ' : 'Done. ')
            ."sites={$totals['sites']}, location_devices={$totals['location_devices']}",
        );

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
