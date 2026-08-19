<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Filament\App\Resources\Users\Pages\EditUser;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserOwnerRoleAssignmentTest extends TestCase
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
    public function pharmacy_system_administrator_cannot_assign_owner_role_on_create(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $actor = $this->createActor(TenantRole::PharmacySystemAdministrator);
            $this->actingAs($actor);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $ownerRoleId = $this->roleId(TenantRole::Owner);
            $roleOptions = Livewire::test(CreateUser::class)
                ->instance()
                ->form
                ->getComponent('roles')
                ->getOptions();
            $this->assertNotContains($ownerRoleId, array_map(intval(...), array_keys($roleOptions)));

            $technicianRoleId = $this->roleId(TenantRole::ReceivingTechnician);
            $email = 'allowed-tech-'.Str::lower(Str::random(8)).'@example.test';

            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Allowed Technician',
                    'email' => $email,
                    'password' => 'password-password',
                    'roles' => [$technicianRoleId],
                    'site_ids' => [$this->defaultSiteId()],
                    'default_site_id' => $this->defaultSiteId(),
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $created = User::query()->where('email', $email)->first();
            $this->assertNotNull($created);
            $this->userIds[] = (int) $created->getKey();
            $this->assertTrue($created->hasRole(TenantRole::ReceivingTechnician->value));
            $this->assertFalse($created->hasRole(TenantRole::Owner->value));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function owner_can_assign_owner_role_on_create(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $actor = $this->createActor(TenantRole::Owner);
            $this->actingAs($actor);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $email = 'new-owner-'.Str::lower(Str::random(8)).'@example.test';

            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Second Owner',
                    'email' => $email,
                    'password' => 'password-password',
                    'roles' => [$this->roleId(TenantRole::Owner)],
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $created = User::query()->where('email', $email)->first();
            $this->assertNotNull($created);
            $this->userIds[] = (int) $created->getKey();
            $this->assertTrue($created->hasRole(TenantRole::Owner->value));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pharmacy_system_administrator_can_edit_owner_without_removing_owner_role(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $owner = $this->createActor(TenantRole::Owner);
            $this->userIds[] = (int) $owner->getKey();

            $actor = $this->createActor(TenantRole::PharmacySystemAdministrator);
            $this->actingAs($actor);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(EditUser::class, ['record' => $owner->getKey()])
                ->fillForm([
                    'name' => 'Owner Renamed By Sysadmin',
                    'email' => $owner->email,
                    'roles' => [$this->roleId(TenantRole::Owner)],
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $owner->refresh();
            $this->assertSame('Owner Renamed By Sysadmin', $owner->name);
            $this->assertTrue($owner->hasRole(TenantRole::Owner->value));
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
                'name' => 'Owner Role Test Site',
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
            'email' => 'owner-role-test-'.Str::lower(Str::random(10)).'@example.test',
        ]);
        $user->syncRoles([$role->value]);
        $user->refresh();

        if ($role !== TenantRole::Owner) {
            $user->sites()->sync([
                $this->defaultSiteId() => ['is_default' => true],
            ]);
        }

        return $user;
    }

    private function roleId(TenantRole $role): int
    {
        return (int) Role::query()
            ->where('guard_name', 'web')
            ->where('name', $role->value)
            ->value('id');
    }

    private function defaultSiteId(): int
    {
        return (int) ($this->siteIds[0] ?? Site::query()->value('id'));
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
