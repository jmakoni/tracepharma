<?php

namespace Tests\Unit\Support\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\Verifications\VerificationResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobRoleAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    #[Test]
    public function allows_nav_capabilities_when_job_roles_are_disabled(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());
        $this->assertTrue(JobRoleAccess::allows(Permissions::NavShip));
        $this->assertTrue(JobRoleAccess::allows(Permissions::NavReceive));
    }

    #[Test]
    public function allows_for_actor_bypasses_nav_gate_for_machine_context_when_job_roles_are_enabled(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
        if ($tenant === null) {
            $this->markTestSkipped('Demo2 tenant not provisioned.');
        }

        $tenant->run(function () use ($tenant): void {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            $this->assertTrue(JobRoleAccess::enabled($tenant));
            $this->assertFalse(JobRoleAccess::allows(Permissions::NavShip));
            $this->assertTrue(JobRoleAccess::allowsForActor(Permissions::NavShip, null));

            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
        });
    }

    #[Test]
    public function owner_retains_all_capabilities_when_job_roles_are_enabled(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
        if ($tenant === null) {
            $this->markTestSkipped('Demo2 tenant not provisioned.');
        }

        $tenant->run(function () use ($tenant): void {
            $profile = $tenant->profile instanceof TenantProfile
                ? $tenant->profile
                : TenantProfile::DrugWholesaler;

            app(TenantRoleSeeder::class)->seedForProfile($profile);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();
            $tenant->refresh();

            $owner = User::query()->where('email', 'owner@demo.test')->first();
            if ($owner === null) {
                $this->markTestSkipped('Demo2 owner missing.');
            }

            $owner->syncRoles([TenantRole::Owner->value]);
            $owner->unsetRelation('roles');
            $owner->unsetRelation('permissions');
            $owner->refresh();
            $this->actingAs($owner);

            $this->assertTrue(JobRoleAccess::enabled($tenant), 'job roles flag should be on');
            $this->assertTrue($owner->can(Permissions::NavReceive), 'owner should have nav.receive');
            $this->assertTrue(JobRoleAccess::allows(Permissions::NavReceive, $owner));
            $this->assertTrue(JobRoleAccess::allows(Permissions::NavShip, $owner));
            $this->assertTrue(ReceivingSessionResource::canAccess());

            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
        });
    }

    #[Test]
    public function persona_without_ship_capability_is_denied_when_job_roles_are_enabled(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
        if ($tenant === null) {
            $this->markTestSkipped('Demo2 tenant not provisioned.');
        }

        $tenant->run(function () use ($tenant): void {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();
            $tenant->refresh();

            $user = User::query()->create([
                'name' => 'VRS Analyst',
                'email' => 'vrs-analyst-'.Str::lower(Str::random(8)).'@example.test',
                'password' => bcrypt('password'),
            ]);
            $user->syncRoles([TenantRole::VrsAnalyst->value]);
            $user->refresh();
            $this->actingAs($user);

            $this->assertTrue(JobRoleAccess::allows(Permissions::NavVerify, $user));
            $this->assertFalse(JobRoleAccess::allows(Permissions::NavReceive, $user));
            $this->assertFalse(JobRoleAccess::allows(Permissions::NavShip, $user));
            $this->assertTrue(VerificationResource::canAccess());
            $this->assertFalse(ReceivingSessionResource::canAccess());

            $user->delete();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
        });
    }

    #[Test]
    public function tenant_role_seeder_maps_personas_to_nav_permissions(): void
    {
        $this->assertContains(Permissions::NavReceive, TenantRoleSeeder::permissionNamesFor(TenantRole::ReceivingTechnician));
        $this->assertNotContains(Permissions::NavShip, TenantRoleSeeder::permissionNamesFor(TenantRole::ReceivingTechnician));
        $this->assertSame(
            Permissions::tenantAppPermissions(),
            TenantRoleSeeder::permissionNamesFor(TenantRole::Owner),
        );
    }

    #[Test]
    public function capability_labels_include_admin_permissions_for_preview(): void
    {
        $labels = TenantRoleSeeder::capabilityLabelsFor(TenantRole::PharmacySystemAdministrator);

        $this->assertContains('Manage users', $labels);
        $this->assertContains('All sites', $labels);
        $this->assertContains('Receive', $labels);
    }

    #[Test]
    public function has_any_app_capability_when_job_roles_are_disabled(): void
    {
        $this->assertFalse(JobRoleAccess::enabled());
        $this->assertTrue(JobRoleAccess::hasAnyAppCapability());
    }

    #[Test]
    public function role_less_user_has_no_app_capability_when_job_roles_are_enabled(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
        if ($tenant === null) {
            $this->markTestSkipped('Demo2 tenant not provisioned.');
        }

        $tenant->run(function () use ($tenant): void {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();
            $tenant->refresh();

            $user = User::query()->create([
                'name' => 'Unassigned User',
                'email' => 'unassigned-'.Str::lower(Str::random(8)).'@example.test',
                'password' => bcrypt('password'),
            ]);
            $user->syncRoles([]);
            $user->refresh();

            $this->assertFalse(JobRoleAccess::hasAnyAppCapability($user));

            $user->delete();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
        });
    }

    #[Test]
    public function persona_with_nav_capability_has_app_capability_when_job_roles_are_enabled(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
        if ($tenant === null) {
            $this->markTestSkipped('Demo2 tenant not provisioned.');
        }

        $tenant->run(function () use ($tenant): void {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();
            $tenant->refresh();

            $user = User::query()->create([
                'name' => 'VRS Analyst',
                'email' => 'vrs-capability-'.Str::lower(Str::random(8)).'@example.test',
                'password' => bcrypt('password'),
            ]);
            $user->syncRoles([TenantRole::VrsAnalyst->value]);
            $user->refresh();

            $this->assertTrue(JobRoleAccess::hasAnyAppCapability($user));

            $user->delete();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
        });
    }

    #[Test]
    public function owner_can_access_organization_settings_when_job_roles_are_enabled(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
        if ($tenant === null) {
            $this->markTestSkipped('Demo2 tenant not provisioned.');
        }

        $tenant->run(function () use ($tenant): void {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
            $tenant->save();

            $owner = User::query()->where('email', 'owner@demo.test')->first();
            if ($owner === null) {
                $this->markTestSkipped('Demo2 owner missing.');
            }

            $owner->syncRoles([TenantRole::Owner->value]);
            $owner->refresh();
            $this->actingAs($owner);

            $this->assertTrue(JobRoleAccess::canAccessOrganizationSettings($owner));

            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();
        });
    }
}
