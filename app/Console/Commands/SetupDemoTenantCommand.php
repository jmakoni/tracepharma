<?php

namespace App\Console\Commands;

use App\Actions\Demo\SeedCatalogMasterData;
use App\Actions\Demo\SeedMasterData;
use App\Actions\Demo\SeedOperationalChoreography;
use App\Enums\AdminRole;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantDatabaseName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetupDemoTenantCommand extends Command
{
    protected $signature = 'tracepharma:setup-demo';

    protected $description = 'Provision the demo2 drug wholesaler (distributor) tenant and default logins';

    public function handle(): int
    {
        $domains = config('tracepharma.demo_domains', []);
        if ($domains === []) {
            $this->error('DEMO_DOMAINS is empty.');

            return self::FAILURE;
        }

        $primary = $domains[0];
        $profile = TenantProfile::DrugWholesaler;

        app(SeedCatalogMasterData::class)->handle();

        app(AdminRoleSeeder::class)->seed();

        $admin = Admin::query()->firstOrCreate(
            ['email' => 'admin@tracepharma.test'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
            ]
        );
        $admin->syncRoles([AdminRole::PlatformAdmin->value]);

        $tenant = Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $primary))->first();

        if (! $tenant) {
            $tenant = Tenant::create([
                'name' => 'Demo Distributor',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => TenantDatabaseName::fromDomain($primary),
                'receiving_state' => 'IL',
            ]);

            foreach ($domains as $domain) {
                $tenant->domains()->firstOrCreate(['domain' => $domain]);
            }
        } else {
            $dirty = false;

            if ($tenant->profile !== $profile) {
                $tenant->profile = $profile;
                $dirty = true;
            }

            if ($tenant->name === 'Demo Pharmacy') {
                $tenant->name = 'Demo Distributor';
                $dirty = true;
            }

            if (blank($tenant->receiving_state)) {
                $tenant->receiving_state = 'IL';
                $dirty = true;
            }

            if ($dirty) {
                $tenant->save();
            }
        }

        $tenant->run(function () use ($profile) {
            app(TenantRoleSeeder::class)->seedForProfile($profile);

            $user = User::query()->updateOrCreate(
                ['email' => 'owner@demo.test'],
                [
                    'name' => 'Demo Owner',
                    'password' => 'password',
                ]
            );
            $user->syncRoles([TenantRole::Owner->value]);

            app(SeedMasterData::class)->handle();
            app(SeedOperationalChoreography::class)->handle();
        });

        $this->info('Demo ready (Drug Wholesaler).');
        $this->line('Admin: https://'.config('tracepharma.admin_domain').' — admin@tracepharma.test / password');
        $this->line('Tenant: https://'.$primary.' — owner@demo.test / password');

        return self::SUCCESS;
    }
}
