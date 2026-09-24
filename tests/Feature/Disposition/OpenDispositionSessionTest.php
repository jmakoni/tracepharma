<?php

namespace Tests\Feature\Disposition;

use App\Actions\Disposition\OpenDispositionSession;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Disposition\DispositionSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenDispositionSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    #[Test]
    public function resume_of_session_with_null_site_fails_with_session_message(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $session = DispositionSession::query()->create([
                'biz_step' => 'urn:epcglobal:cbv:bizstep:destroying',
                'site_id' => null,
                'status' => 'open',
                'staged_count' => 0,
                'confirmed_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionIds[] = (int) $session->getKey();

            try {
                app(OpenDispositionSession::class)->handle(
                    'urn:epcglobal:cbv:bizstep:destroying',
                    existingSessionId: (int) $session->getKey(),
                );
                $this->fail('Resume with a null site should fail.');
            } catch (AuthorizationException $exception) {
                $this->fail('Null site must not surface as a site-access denial: '.$exception->getMessage());
            } catch (DomainException $exception) {
                $this->assertStringContainsString('no site', $exception->getMessage());
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function user_with_only_site_a_cannot_resume_exclusive_session_at_site_b(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $siteA = Site::factory()->owned()->create([
                'name' => 'Disposition Site A',
                'is_active' => true,
            ]);
            $siteB = Site::factory()->owned()->create([
                'name' => 'Disposition Site B',
                'is_active' => true,
            ]);
            $this->siteIds = [(int) $siteA->getKey(), (int) $siteB->getKey()];

            $session = DispositionSession::query()->create([
                'biz_step' => 'urn:epcglobal:cbv:bizstep:destroying',
                'site_id' => (int) $siteB->getKey(),
                'status' => 'open',
                'staged_count' => 0,
                'confirmed_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionIds[] = (int) $session->getKey();

            $user = User::factory()->create();
            $user->syncSites([(int) $siteA->getKey()]);
            $user->givePermissionTo(Permissions::NavShip);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            try {
                app(OpenDispositionSession::class)->handle(
                    'urn:epcglobal:cbv:bizstep:destroying',
                    existingSessionId: (int) $session->getKey(),
                );
                $this->fail('Expected AuthorizationException for cross-site disposition resume.');
            } catch (AuthorizationException $exception) {
                $this->assertStringContainsString('site', strtolower($exception->getMessage()));
            }

            $this->assertSame('open', $session->fresh()?->status);
        } finally {
            $this->cleanup();
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

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionIds !== []) {
            DispositionSession::query()->whereIn('id', $this->sessionIds)->delete();
            $this->sessionIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        tenancy()->end();
    }
}
