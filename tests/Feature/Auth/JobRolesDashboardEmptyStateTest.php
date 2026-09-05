<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\OrganizationSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class JobRolesDashboardEmptyStateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?bool $priorJobRolesEnabled = null;

    #[Test]
    public function dashboard_shows_buying_group_limited_banner_for_owner(): void
    {
        $tenant = $this->initializeBuyingGroupTenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::BuyingGroup);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(Dashboard::class)
                ->assertOk()
                ->assertSee('Buying group control plane')
                ->assertSee('Partner ATP readiness')
                ->assertSee('Alert center')
                ->assertSee('Member roster');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function dashboard_shows_no_role_banner_when_job_roles_enabled_and_user_has_zero_roles(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->enableJobRoles($tenant);

            $user = User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(Dashboard::class)
                ->assertOk()
                ->assertSee('No job role assigned')
                ->assertSee('Contact an Owner or administrator');

            $this->assertFalse(OperationsHub::canAccess());
            $this->assertFalse(OrganizationSettings::canAccess());
            $this->assertFalse(JobRoleAccess::canAccessOrganizationSettings($user));

            Livewire::test(OperationsHub::class)->assertForbidden();
            Livewire::test(OrganizationSettings::class)->assertForbidden();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function dashboard_does_not_show_no_role_banner_when_job_roles_are_disabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();

            $user = User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(Dashboard::class)
                ->assertOk()
                ->assertDontSee('No job role assigned');
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function enableJobRoles(Tenant $tenant): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
        $tenant->save();
    }

    private function initializeBuyingGroupTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Buying Group',
                'profile' => TenantProfile::BuyingGroup,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->forceFill(['profile' => TenantProfile::BuyingGroup])->save();
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->priorJobRolesEnabled = TenantSettings::forTenant($tenant)->jobRolesEnabled();

        return $tenant;
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->priorJobRolesEnabled = TenantSettings::forTenant($tenant)->jobRolesEnabled();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized && $this->priorJobRolesEnabled !== null) {
            TenantSettings::forTenant($tenant->fresh() ?? $tenant)
                ->setJobRolesEnabled($this->priorJobRolesEnabled);
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorJobRolesEnabled = null;
    }
}
