<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupportEngineerUserAssignmentTest extends TestCase
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
    public function owner_can_create_support_engineer_with_tracepharma_email_and_password_is_randomized(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DentalMedicalSupply);

        try {
            Notification::fake();

            $owner = $this->createActor(TenantRole::Owner);
            $this->userIds[] = (int) $owner->getKey();
            $this->actingAs($owner);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $email = 'support-'.Str::lower(Str::random(8)).'@tracepharma.io';
            $submittedPassword = 'creator-chosen-password-should-not-stick';

            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Support Seat',
                    'email' => $email,
                    'password' => $submittedPassword,
                    'roles' => [$this->roleId(TenantRole::SupportEngineer)],
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $created = User::query()->where('email', $email)->first();
            $this->assertNotNull($created);
            $this->userIds[] = (int) $created->getKey();
            $this->assertTrue($created->hasRole(TenantRole::SupportEngineer->value));
            $this->assertFalse($created->hasRole(TenantRole::Owner->value));
            $this->assertFalse(Hash::check($submittedPassword, (string) $created->password));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function owner_cannot_create_support_engineer_with_non_tracepharma_email(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DentalMedicalSupply);

        try {
            $owner = $this->createActor(TenantRole::Owner);
            $this->userIds[] = (int) $owner->getKey();
            $this->actingAs($owner);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $email = 'not-support-'.Str::lower(Str::random(8)).'@gmail.com';

            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Bad Support',
                    'email' => $email,
                    'password' => 'password-password',
                    'roles' => [$this->roleId(TenantRole::SupportEngineer)],
                ])
                ->call('create')
                ->assertHasErrors();

            $this->assertNull(User::query()->where('email', $email)->first());
            $this->assertTrue(
                User::query()->where('email', $email)->doesntExist(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dental_profile_create_form_lists_floor_roles(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DentalMedicalSupply);

        try {
            $owner = $this->createActor(TenantRole::Owner);
            $this->userIds[] = (int) $owner->getKey();
            $this->actingAs($owner);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $options = Livewire::test(CreateUser::class)
                ->instance()
                ->form
                ->getComponent('roles')
                ->getOptions();

            $labels = array_map('strval', array_values($options));
            $this->assertContains('Receiving Technician', $labels);
            $this->assertContains('Outbound Pick-and-Pack Lead', $labels);
            $this->assertContains('Inbound Exception Coordinator', $labels);
            $this->assertContains('Support Engineer', $labels);
        } finally {
            $this->cleanup();
        }
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            $tenant->forceFill(['profile' => $profile])->save();
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        app(TenantRoleSeeder::class)->seedForProfile($profile);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $site = Site::query()->firstOrCreate(
            ['gln' => '0366159000040'],
            [
                'name' => 'Support Role Test Site',
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
            'email' => 'support-role-actor-'.Str::lower(Str::random(10)).'@example.test',
        ]);
        $user->syncRoles([$role->value]);
        $user->refresh();

        return $user;
    }

    private function roleId(TenantRole $role): int
    {
        return (int) Role::query()
            ->where('guard_name', 'web')
            ->where('name', $role->value)
            ->value('id');
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->userIds !== []) {
            User::query()->whereKey($this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();
    }
}
