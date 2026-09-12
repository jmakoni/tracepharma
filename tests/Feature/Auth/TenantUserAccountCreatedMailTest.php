<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Users\Pages\CreateUser;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantUserAccountCreated;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantUserAccountCreatedMailTest extends TestCase
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
    public function create_user_emails_account_created_without_password(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();

            $actor = $this->createActor(TenantRole::Owner);
            $this->userIds[] = (int) $actor->getKey();
            $this->actingAs($actor);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $email = 'new-user-mail-'.Str::lower(Str::random(8)).'@example.test';
            $password = 'secret-password-never-in-mail';

            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Mail Recipient',
                    'email' => $email,
                    'password' => $password,
                    'roles' => [$this->roleId(TenantRole::ReceivingTechnician)],
                    'site_ids' => [$this->defaultSiteId()],
                    'default_site_id' => $this->defaultSiteId(),
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $created = User::query()->where('email', $email)->first();
            $this->assertNotNull($created);
            $this->userIds[] = (int) $created->getKey();

            Notification::assertSentOnDemand(
                TenantUserAccountCreated::class,
                function (TenantUserAccountCreated $notification, array $channels, object $notifiable) use ($email, $password): bool {
                    if (($notifiable->routes['mail'] ?? null) !== $email) {
                        return false;
                    }

                    $mail = $notification->toMail($notifiable);
                    $body = implode("\n", $mail->introLines);

                    return str_contains($body, $email)
                        && str_contains($body, self::DEMO2_DOMAIN)
                        && str_contains($body, 'the password your administrator set')
                        && ! str_contains($body, $password)
                        && $mail->actionUrl === 'https://'.self::DEMO2_DOMAIN;
                },
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function create_support_engineer_emails_forgot_password_not_admin_set_password(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();

            $actor = $this->createActor(TenantRole::Owner);
            $this->userIds[] = (int) $actor->getKey();
            $this->actingAs($actor);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $email = 'support-mail-'.Str::lower(Str::random(8)).'@tracepharma.io';
            $password = 'creator-chosen-password-should-not-be-cited';

            Livewire::test(CreateUser::class)
                ->fillForm([
                    'name' => 'Support Mail Recipient',
                    'email' => $email,
                    'password' => $password,
                    'roles' => [$this->roleId(TenantRole::SupportEngineer)],
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $created = User::query()->where('email', $email)->first();
            $this->assertNotNull($created);
            $this->userIds[] = (int) $created->getKey();

            Notification::assertSentOnDemand(
                TenantUserAccountCreated::class,
                function (TenantUserAccountCreated $notification, array $channels, object $notifiable) use ($email, $password): bool {
                    if (($notifiable->routes['mail'] ?? null) !== $email) {
                        return false;
                    }

                    $mail = $notification->toMail($notifiable);
                    $body = implode("\n", $mail->introLines);

                    return str_contains($body, $email)
                        && str_contains($body, self::DEMO2_DOMAIN)
                        && str_contains($body, 'Forgot password')
                        && ! str_contains($body, 'the password your administrator set')
                        && ! str_contains($body, $password)
                        && $mail->actionUrl === 'https://'.self::DEMO2_DOMAIN;
                },
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
