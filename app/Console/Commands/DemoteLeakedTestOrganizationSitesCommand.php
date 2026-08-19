<?php

namespace App\Console\Commands;

use App\Actions\MasterData\DemoteLeakedTestOrganizationSites;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class DemoteLeakedTestOrganizationSitesCommand extends Command
{
    protected $signature = 'tracepharma:sites-demote-test-leaks
        {--tenants=* : Tenant id(s) to repair; default all}';

    protected $description = 'Demote leaked test organization facilities and clear tenant default site pointers';

    public function handle(DemoteLeakedTestOrganizationSites $demote): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return self::SUCCESS;
        }

        $totals = [
            'tenants' => 0,
            'demoted' => 0,
            'defaults_cleared' => 0,
        ];

        foreach ($tenants as $tenant) {
            $result = $tenant->run(fn (): array => $demote->handle());

            $totals['tenants']++;
            $totals['demoted'] += $result['demoted'];
            $totals['defaults_cleared'] += $result['defaults_cleared'];

            $this->line(
                "[{$tenant->id}] {$tenant->name}: demoted={$result['demoted']}, defaults_cleared={$result['defaults_cleared']}",
            );
        }

        $this->info(
            "Done. tenants={$totals['tenants']}, demoted={$totals['demoted']}, defaults_cleared={$totals['defaults_cleared']}",
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
