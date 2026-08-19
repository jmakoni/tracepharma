<?php

namespace App\Console\Commands;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\Auth\TenantRoleSeeder;
use Illuminate\Console\Command;

class SeedTenantJobRolesCommand extends Command
{
    protected $signature = 'tracepharma:seed-tenant-job-roles
                            {--tenants=* : Tenant IDs (default: all)}';

    protected $description = 'Create/sync atomic nav.* permissions and persona role bundles for tenant(s).';

    public function handle(TenantRoleSeeder $seeder): int
    {
        $ids = $this->option('tenants');
        $query = Tenant::query()->orderBy('id');
        if (is_array($ids) && $ids !== []) {
            $query->whereIn('id', $ids);
        }

        $count = 0;
        foreach ($query->cursor() as $tenant) {
            /** @var Tenant $tenant */
            $profile = $tenant->profile instanceof TenantProfile
                ? $tenant->profile
                : TenantProfile::tryFrom((string) $tenant->profile) ?? TenantProfile::Pharmacy;

            $tenant->run(function () use ($seeder, $profile): void {
                $seeder->seedForProfile($profile);
            });

            $this->line("Seeded job-role permissions for {$tenant->getKey()} ({$profile->value})");
            $count++;
        }

        $this->info("Done. {$count} tenant(s).");

        return self::SUCCESS;
    }
}
