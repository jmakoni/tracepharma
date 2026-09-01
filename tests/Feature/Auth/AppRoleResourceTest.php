<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Roles\Pages\EditRole;
use App\Filament\App\Resources\Roles\Pages\ListRoles;
use App\Filament\App\Resources\Roles\RoleResource;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AppRoleResourceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    #[Test]
    public function user_without_users_manage_cannot_access_roles(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $actor = $this->createActor(TenantRole::ReceivingTechnician);
            $this->actingAs($actor);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertFalse(RoleResource::canAccess());
            Livewire::test(ListRoles::class)->assertForbidden();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_with_users_manage_can_sync_and_reset_role_permissions(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $actor = $this->createActor(TenantRole::PharmacySystemAdministrator);
            $this->actingAs($actor);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertTrue(RoleResource::canAccess());
            $this->assertFalse(RoleResource::canCreate());

            $role = Role::query()
                ->where('guard_name', 'web')
                ->where('name', TenantRole::ReceivingTechnician->value)
                ->firstOrFail();

            $this->assertFalse(RoleResource::canDelete($role));

            Livewire::test(ListRoles::class)->assertSuccessful();

            Livewire::test(EditRole::class, ['record' => $role->getKey()])
                ->assertSuccessful()
                ->fillForm([
                    'permission_names' => [
                        Permissions::NavReceive,
                        Permissions::NavExceptions,
                    ],
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $role->refresh();
            $this->assertEqualsCanonicalizing(
                [Permissions::NavReceive, Permissions::NavExceptions],
                $role->permissions()->pluck('name')->all(),
            );

            Livewire::test(EditRole::class, ['record' => $role->getKey()])
                ->mountAction('resetToDefaults')
                ->callMountedAction()
                ->assertHasNoErrors();

            $role->refresh();
            $this->assertEqualsCanonicalizing(
                TenantRoleSeeder::permissionNamesFor(TenantRole::ReceivingTechnician),
                $role->permissions()->pluck('name')->all(),
            );
        } finally {
            $this->cleanup($tenant);
        }
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
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $site = Site::query()->firstOrCreate(
            ['gln' => '0366159000040'],
            [
                'name' => 'Role Resource Test Site',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ],
        );
        $this->siteIds[] = (int) $site->getKey();
        TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId((int) $site->getKey());
        $tenant->save();

        return $tenant;
    }

    private function createActor(TenantRole $role): User
    {
        $user = User::factory()->create([
            'email' => 'app-role-resource-'.Str::lower(Str::random(10)).'@example.test',
        ]);
        $user->syncRoles([$role->value]);
        $user->refresh();
        $this->userIds[] = (int) $user->getKey();

        if ($role !== TenantRole::Owner) {
            $user->sites()->sync([
                $this->siteIds[0] => ['is_default' => true],
            ]);
        }

        return $user;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->userIds !== []) {
            User::query()->whereKey($this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereKey($this->siteIds)->delete();
            $this->siteIds = [];
        }

        tenancy()->end();
    }
}
