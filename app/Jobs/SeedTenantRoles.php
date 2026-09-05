<?php

namespace App\Jobs;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class SeedTenantRoles implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        protected TenantWithDatabase $tenant,
    ) {}

    public function handle(TenantRoleSeeder $seeder): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenant;

        $profile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile) ?? TenantProfile::Pharmacy;

        TenantRunner::run($tenant, function () use ($seeder, $profile): void {
            $seeder->seedForProfile($profile);
        });
    }
}
