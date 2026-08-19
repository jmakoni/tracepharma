<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Admin\StartTenantUserImpersonation;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\AdminRole;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Admin\TenantImpersonation;
use App\Support\TenantHostname;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Tests\TestCase;

class TenantUserImpersonationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    private array $slugs = [];

    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->slugs as $slug) {
            foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
                $domain = Domain::query()
                    ->where('domain', TenantHostname::forSlug($slug, $environment))
                    ->first();

                if ($domain === null) {
                    continue;
                }

                Tenant::withoutEvents(
                    fn () => Tenant::query()->find($domain->tenant_id)?->delete(),
                );
            }
        }

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function support_cannot_impersonate_tenant_users(): void
    {
        ['tenant' => $tenant] = $this->provisionTenantWithOwner();

        $this->actAsAdmin(AdminRole::Support);

        Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
            ->assertForbidden();
    }

    #[Test]
    public function platform_admin_can_start_impersonation_for_active_tenant(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->provisionTenantWithOwner();
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
            ->assertActionVisible(TestAction::make('impersonateTenantUser'))
            ->assertActionEnabled(TestAction::make('impersonateTenantUser'));

        $url = app(StartTenantUserImpersonation::class)->execute(
            $admin,
            $tenant,
            (string) $owner->id,
            'Customer support ticket #12345',
        );

        $this->assertStringContainsString('/impersonate/', $url);

        $token = ImpersonationToken::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', (string) $owner->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($token);
        $this->assertSame((string) $admin->id, (string) $token->admin_id);
        $this->assertSame('Customer support ticket #12345', $token->reason);
    }

    #[Test]
    public function suspended_tenant_cannot_be_impersonated(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->provisionTenantWithOwner();
        $tenant->update(['status' => 'suspended']);

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(EditTenant::class, ['record' => $tenant->getKey()])
            ->assertActionDisabled(TestAction::make('impersonateTenantUser'));

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot impersonate users on a suspended tenant.');

        app(StartTenantUserImpersonation::class)->execute(
            $admin,
            $tenant->fresh(),
            (string) $owner->id,
            'Should not be allowed',
        );
    }

    #[Test]
    public function impersonation_start_is_logged_to_central_activity_log(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->provisionTenantWithOwner();
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        app(StartTenantUserImpersonation::class)->execute(
            $admin,
            $tenant,
            (string) $owner->id,
            'Audit verification for TM-2',
            '203.0.113.10',
        );

        $activity = Activity::query()
            ->where('description', 'tenant_user_impersonation_started')
            ->where('causer_type', Admin::class)
            ->where('causer_id', $admin->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('admin', $activity->log_name);
        $this->assertSame($tenant->getKey(), data_get($activity->properties, 'tenant_id'));
        $this->assertSame((string) $owner->id, data_get($activity->properties, 'target_user_id'));
        $this->assertSame($owner->email, data_get($activity->properties, 'target_user_email'));
        $this->assertSame('Audit verification for TM-2', data_get($activity->properties, 'reason'));
        $this->assertSame('203.0.113.10', data_get($activity->properties, 'ip'));

        $properties = $activity->properties->toArray();
        $this->assertArrayNotHasKey('token', $properties);

        $tokenHash = data_get($activity->properties, 'token_hash');
        $this->assertIsString($tokenHash);
        $this->assertSame(16, strlen($tokenHash));

        $rawToken = ImpersonationToken::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', (string) $owner->id)
            ->latest('created_at')
            ->value('token');

        $this->assertNotSame($rawToken, $tokenHash);
        $this->assertSame(substr(hash('sha256', (string) $rawToken), 0, 16), $tokenHash);
    }

    #[Test]
    public function impersonation_route_logs_in_user_and_sets_session_banner_state(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'domain' => $domain] = $this->provisionTenantWithOwner();
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        app(StartTenantUserImpersonation::class)->execute(
            $admin,
            $tenant,
            (string) $owner->id,
            'Route redemption test',
        );

        $token = ImpersonationToken::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', (string) $owner->id)
            ->latest('created_at')
            ->value('token');

        $this->assertNotNull($token);

        $response = $this->get('https://'.$domain.'/impersonate/'.$token, [
            'HTTP_HOST' => $domain,
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($owner, 'web');
        $this->assertTrue(TenantImpersonation::isActive());
        $this->assertSame((string) $admin->id, (string) TenantImpersonation::adminId());
        $this->assertSame('Route redemption test', TenantImpersonation::reason());
        $this->assertNull(ImpersonationToken::query()->find($token));
    }

    #[Test]
    public function impersonation_token_can_only_be_redeemed_once(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'domain' => $domain] = $this->provisionTenantWithOwner();
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        app(StartTenantUserImpersonation::class)->execute(
            $admin,
            $tenant,
            (string) $owner->id,
            'Single-use token test',
        );

        $token = ImpersonationToken::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', (string) $owner->id)
            ->latest('created_at')
            ->value('token');

        $this->assertNotNull($token);

        $first = $this->get('https://'.$domain.'/impersonate/'.$token, [
            'HTTP_HOST' => $domain,
        ]);

        $first->assertRedirect('/');
        $this->assertAuthenticatedAs($owner, 'web');

        auth('web')->logout();

        $this->get('https://'.$domain.'/impersonate/'.$token, [
            'HTTP_HOST' => $domain,
        ])->assertNotFound();
    }

    #[Test]
    public function impersonation_end_is_logged_when_tenant_user_logs_out(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'domain' => $domain] = $this->provisionTenantWithOwner();
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        app(StartTenantUserImpersonation::class)->execute(
            $admin,
            $tenant,
            (string) $owner->id,
            'Logout audit test',
        );

        $token = ImpersonationToken::query()
            ->where('tenant_id', $tenant->getKey())
            ->latest('created_at')
            ->value('token');

        $this->get('https://'.$domain.'/impersonate/'.$token, ['HTTP_HOST' => $domain]);

        auth('web')->logout();
        event(new Logout('web', $owner));

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $activity = Activity::query()
            ->where('description', 'tenant_user_impersonation_ended')
            ->where('causer_type', Admin::class)
            ->where('causer_id', $admin->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($tenant->getKey(), data_get($activity->properties, 'tenant_id'));
        $this->assertSame((string) $owner->id, data_get($activity->properties, 'target_user_id'));
        $this->assertSame('Logout audit test', data_get($activity->properties, 'reason'));
    }

    /**
     * @return array{tenant: Tenant, owner: User, domain: string}
     */
    private function provisionTenantWithOwner(): array
    {
        $slug = 'imp-'.Str::lower(Str::random(8));
        $this->slugs[] = $slug;
        $ownerEmail = 'owner-'.$slug.'@example.test';

        $tenant = app(ProvisionTenantPair::class)->create($slug, [
            'name' => 'Impersonation Test '.$slug,
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
        ], owner: [
            'name' => 'Owner '.$slug,
            'email' => $ownerEmail,
            'password' => 'password12',
        ]);

        $owner = $tenant->run(function () use ($ownerEmail): User {
            $user = User::query()->where('email', $ownerEmail)->first();
            $this->assertNotNull($user);
            $this->assertTrue($user->hasRole(TenantRole::Owner->value));

            return $user;
        });

        $domain = (string) $tenant->domains()->orderBy('id')->value('domain');

        return [
            'tenant' => $tenant,
            'owner' => $owner,
            'domain' => $domain,
        ];
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}
