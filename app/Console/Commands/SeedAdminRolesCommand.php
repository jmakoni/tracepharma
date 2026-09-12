<?php

namespace App\Console\Commands;

use App\Support\Auth\AdminRoleSeeder;
use Illuminate\Console\Command;

class SeedAdminRolesCommand extends Command
{
    protected $signature = 'tracepharma:seed-admin-roles';

    protected $description = 'Create/sync admin panel Spatie permissions and platform_admin / support role bundles.';

    public function handle(AdminRoleSeeder $seeder): int
    {
        $seeder->seed();
        $this->info('Admin role matrix seeded (platform_admin + support).');

        return self::SUCCESS;
    }
}
