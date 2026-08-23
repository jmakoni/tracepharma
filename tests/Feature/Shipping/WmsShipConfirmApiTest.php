<?php

namespace Tests\Feature\Shipping;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\SanctumAbilities;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WmsShipConfirmApiTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function sanctum_route_is_registered_beside_the_webhook(): void
    {
        $sanctum = Route::getRoutes()->getByName('api.v1.wms.ship-confirm');
        $webhook = Route::getRoutes()->getByName('webhooks.wms.ship-confirm');

        $this->assertNotNull($sanctum);
        $this->assertNotNull($webhook);
        $this->assertContains('abilities:wms:ship-confirm', $sanctum->gatherMiddleware());
        $this->assertContains('auth:sanctum', $sanctum->gatherMiddleware());
    }

    #[Test]
    public function pharmacy_token_is_forbidden_and_webhook_stays_separate(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            $token = $this->createToken(TenantProfile::Pharmacy);

            tenancy()->end();

            $this->call(
                'POST',
                'http://'.self::DEMO2_DOMAIN.'/api/v1/wms/ship-confirm',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'CONTENT_TYPE' => 'application/json',
                ],
                json_encode(['scans' => ['(00)000000000000000000']], JSON_THROW_ON_ERROR),
            )->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function wholesaler_token_without_scans_is_unprocessable(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            $token = $this->createToken(TenantProfile::DrugWholesaler);

            tenancy()->end();

            $this->call(
                'POST',
                'http://'.self::DEMO2_DOMAIN.'/api/v1/wms/ship-confirm',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'CONTENT_TYPE' => 'application/json',
                ],
                json_encode(['scans' => []], JSON_THROW_ON_ERROR),
            )->assertStatus(422);
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
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => $profile])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }

    private function createToken(TenantProfile $profile): string
    {
        app(TenantRoleSeeder::class)->seedForProfile($profile);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user->createToken('wms-api', [SanctumAbilities::WMS_SHIP_CONFIRM])->plainTextToken;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->userIds as $id) {
            User::query()->whereKey($id)->delete();
        }
        $this->userIds = [];

        $tenant = tenant();
        if ($this->priorProfile !== null && $tenant !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
            $this->priorProfile = null;
        }

        tenancy()->end();
    }
}
