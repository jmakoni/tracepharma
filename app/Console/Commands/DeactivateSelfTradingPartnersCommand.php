<?php

namespace App\Console\Commands;

use App\Actions\MasterData\DeactivateSelfTradingPartners;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class DeactivateSelfTradingPartnersCommand extends Command
{
    protected $signature = 'tracepharma:partners-deactivate-self
        {--tenants=* : Tenant id(s) to heal; default all}';

    protected $description = 'Deactivate trading partners whose GLN is the organization\'s own (self-partners) and hand the organization GLN back to an organization facility';

    public function handle(DeactivateSelfTradingPartners $deactivate): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return self::SUCCESS;
        }

        $totals = [
            'tenants' => 0,
            'partners_deactivated' => 0,
            'partners_renamed' => 0,
            'sites_promoted' => 0,
        ];

        foreach ($tenants as $tenant) {
            $result = TenantRunner::run($tenant, fn (): array => $deactivate->handle());

            $totals['tenants']++;
            $totals['partners_deactivated'] += $result['partners_deactivated'];
            $totals['partners_renamed'] += $result['partners_renamed'];
            $totals['sites_promoted'] += $result['sites_promoted'];

            $this->line(sprintf(
                '[%s] %s: deactivated=%d, renamed=%d, sites_promoted=%d',
                $tenant->id,
                $tenant->name,
                $result['partners_deactivated'],
                $result['partners_renamed'],
                $result['sites_promoted'],
            ));
        }

        $this->info(sprintf(
            'Done. tenants=%d, deactivated=%d, renamed=%d, sites_promoted=%d',
            $totals['tenants'],
            $totals['partners_deactivated'],
            $totals['partners_renamed'],
            $totals['sites_promoted'],
        ));

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
