<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\OnboardingWizard;
use App\Filament\App\Pages\OrganizationSettings;
use App\Filament\App\Pages\SettingsHub;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class JobRolesOrganizationSettingsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $siteId = null;

    private ?bool $priorJobRolesEnabled = null;

    #[Test]
    public function owner_can_enable_job_roles_via_organization_settings_and_still_access_settings(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();

            // Org GLN is shared across demo2 settings tests; reuse if a prior run left it.
            $site = Site::query()->firstOrCreate(
                ['gln' => '0366159000026'],
                [
                    'name' => 'Job Roles Enable Site',
                    'is_active' => true,
                    'is_headquarters' => true,
                    'is_organization_facility' => true,
                ],
            );
            $site->forceFill([
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ])->save();
            $this->siteId = $site->wasRecentlyCreated ? (int) $site->getKey() : null;

            $owner = $this->createOwnerWithoutSeededPermissions();
            $this->actingAs($owner);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertFalse(JobRoleAccess::enabled($tenant));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'gln' => '0366159000026',
                    'company_prefix' => '036615',
                    'receiving_state' => 'IL',
                    'default_receive_site_id' => $this->siteId,
                    'compliance_contact_name' => 'Job Roles Owner',
                    'compliance_contact_email' => 'job-roles-owner@example.test',
                    'job_roles_enabled' => true,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $tenant->refresh();
            $owner->unsetRelation('roles');
            $owner->unsetRelation('permissions');
            $owner->refresh();

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->assertTrue(TenantSettings::forTenant($tenant)->jobRolesEnabled());
            $this->assertTrue(JobRoleAccess::enabled($tenant));
            $this->assertTrue($owner->can(Permissions::NavMasterData));
            $this->assertTrue($owner->can(Permissions::NavReceive));
            $this->assertTrue(JobRoleAccess::canAccessOrganizationSettings($owner));

            $receivingRole = Role::findByName(TenantRole::ReceivingTechnician->value, 'web');
            $this->assertTrue($receivingRole->hasPermissionTo(Permissions::NavReceive));

            Livewire::test(OrganizationSettings::class)->assertOk();
            Livewire::test(SettingsHub::class)->assertOk();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function plain_user_is_denied_organization_settings_when_job_roles_are_disabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();

            $user = User::factory()->create();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertFalse(JobRoleAccess::canAccessOrganizationSettings($user));

            Livewire::test(OrganizationSettings::class)->assertForbidden();
            Livewire::test(SettingsHub::class)->assertForbidden();
            Livewire::test(OnboardingWizard::class)->assertForbidden();
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOwnerWithoutSeededPermissions(): User
    {
        $ownerRole = Role::findOrCreate(TenantRole::Owner->value, 'web');
        $ownerRole->syncPermissions([]);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        return $user;
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
        if ($this->siteId !== null && tenancy()->initialized) {
            Site::query()->whereKey($this->siteId)->delete();
            $this->siteId = null;
        }

        if (tenancy()->initialized && $this->priorJobRolesEnabled !== null) {
            TenantSettings::forTenant($tenant->fresh() ?? $tenant)
                ->setJobRolesEnabled($this->priorJobRolesEnabled);
            $tenant->save();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorJobRolesEnabled = null;
    }
}
