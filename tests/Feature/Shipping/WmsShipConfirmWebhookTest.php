<?php

namespace Tests\Feature\Shipping;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WmsShipConfirmWebhookTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const WMS_KEY = 'test-wms-bridge-key';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    private bool $clearedTenantBridgeKey = false;

    /** @var list<string> */
    private array $extraTenantIds = [];

    #[Test]
    public function production_rejects_missing_tenant_bridge_key(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            TenantSettings::forTenant($tenant)->setWmsBridgeApiKey(null);
            $tenant->save();
            $this->clearedTenantBridgeKey = true;

            config(['integrations.wms.api_key' => self::WMS_KEY]);
            $this->app->detectEnvironment(fn () => 'production');
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
                ['scans' => ['(01)30301164005162(21)ABC123']],
                ['X-Wms-Api-Key' => self::WMS_KEY],
            )->assertStatus(503);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
            $this->cleanup();
        }
    }

    #[Test]
    public function non_production_rejects_missing_tenant_bridge_key(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            TenantSettings::forTenant($tenant)->setWmsBridgeApiKey(null);
            $tenant->save();
            $this->clearedTenantBridgeKey = true;

            config(['integrations.wms.api_key' => self::WMS_KEY]);
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
                ['scans' => ['(01)30301164005162(21)WMS-ENV-FALLBACK']],
                ['X-Wms-Api-Key' => self::WMS_KEY],
            )->assertStatus(503);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function webhook_rejects_tenant_a_key_against_tenant_b(): void
    {
        $tenantA = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);
        $tenantBId = (string) Str::uuid();
        $tenantB = Tenant::withoutEvents(fn () => Tenant::query()->create([
            'id' => $tenantBId,
            'name' => 'WMS Bridge Tenant B',
            'profile' => TenantProfile::DrugWholesaler,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_wms_bridge_b_'.str_replace('-', '', $tenantBId),
        ]));
        $this->extraTenantIds[] = $tenantBId;

        try {
            config(['integrations.wms.api_key' => null]);

            TenantSettings::forTenant($tenantA)->setWmsBridgeApiKey('tenant-a-key');
            $tenantA->save();

            TenantSettings::forTenant($tenantB)->setWmsBridgeApiKey('tenant-b-key');
            $tenantB->save();

            tenancy()->end();

            $this->postJson(
                '/api/webhooks/wms/'.$tenantBId,
                ['scans' => ['(01)30301164005162(21)ABC123']],
                ['X-Wms-Api-Key' => 'tenant-a-key'],
            )->assertUnauthorized();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function webhook_prefers_tenant_key_over_shared_env_key(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            TenantSettings::forTenant($tenant)->setWmsBridgeApiKey('tenant-only-key');
            $tenant->save();
            $this->clearedTenantBridgeKey = true;

            config(['integrations.wms.api_key' => self::WMS_KEY]);
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
                ['scans' => ['(01)30301164005162(21)ABC123']],
                ['X-Wms-Api-Key' => self::WMS_KEY],
            )->assertUnauthorized();

            $authorized = $this->postJson(
                '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
                ['scans' => ['(01)30301164005162(21)ABC123']],
                ['X-Wms-Api-Key' => 'tenant-only-key'],
            );

            $this->assertNotSame(401, $authorized->status());
            $this->assertNotSame(503, $authorized->status());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function webhook_rejects_invalid_api_key(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenantBridgeKey($tenant);

        try {
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
                ['scans' => ['(01)30301164005162(21)ABC123']],
                ['X-Wms-Api-Key' => 'wrong-key'],
            )->assertUnauthorized();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function pharmacy_tenant_returns_403_for_outbound_webhook(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);
        $this->configureTenantBridgeKey($tenant);

        try {
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
                ['scans' => ['(01)30301164005162(21)ABC123']],
                ['X-Wms-Api-Key' => self::WMS_KEY],
            )->assertForbidden()
                ->assertJson(['message' => 'Outbound shipping is not available for this tenant profile.']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function webhook_accepts_complete_false(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);
        $this->configureTenantBridgeKey($tenant);

        try {
            config(['tracepharma.epcis.enforce_atp_outbound_gate' => false]);
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
                [
                    'scans' => ['(01)30301164005162(21)WMS-COMPLETE-FALSE'],
                    'complete' => false,
                ],
                [
                    'X-Wms-Api-Key' => self::WMS_KEY,
                    'Idempotency-Key' => 'webhook-complete-false-'.uniqid('', true),
                ],
            )->assertStatus(422);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function production_requires_idempotency_key_header(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);

        try {
            TenantSettings::forTenant($tenant)->setWmsBridgeApiKey(self::WMS_KEY);
            $tenant->save();

            config(['integrations.wms.api_key' => self::WMS_KEY]);
            $this->app->detectEnvironment(fn () => 'production');
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
                ['scans' => ['(01)30301164005162(21)ABC123']],
                ['X-Wms-Api-Key' => self::WMS_KEY],
            )->assertUnprocessable()
                ->assertJson(['message' => 'Idempotency-Key header is required.']);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
            $this->cleanup();
        }
    }

    #[Test]
    public function webhook_requires_scans_array(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::DrugWholesaler);
        $this->configureTenantBridgeKey($tenant);

        try {
            tenancy()->end();

            $this->postJson(
                '/api/webhooks/wms/'.self::DEMO2_TENANT_ID,
                [],
                ['X-Wms-Api-Key' => self::WMS_KEY],
            )->assertUnprocessable()
                ->assertJsonValidationErrors(['scans']);
        } finally {
            $this->cleanup();
        }
    }

    private function configureTenantBridgeKey(Tenant $tenant, string $key = self::WMS_KEY): void
    {
        TenantSettings::forTenant($tenant)->setWmsBridgeApiKey($key);
        $tenant->save();
    }

    private function initializeDemo2Tenant(?TenantProfile $profile = null): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Tenant',
                'profile' => $profile ?? TenantProfile::Pharmacy,
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

        if ($profile !== null) {
            $tenant->forceFill(['profile' => $profile])->save();
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function cleanup(): void
    {
        if ($this->priorProfile !== null || $this->clearedTenantBridgeKey) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                if ($this->priorProfile !== null) {
                    $tenant->forceFill(['profile' => $this->priorProfile])->save();
                }

                if ($this->clearedTenantBridgeKey) {
                    TenantSettings::forTenant($tenant)->setWmsBridgeApiKey(null);
                    $tenant->save();
                }
            }
        }

        foreach ($this->extraTenantIds as $tenantId) {
            Tenant::withoutEvents(fn () => Tenant::query()->whereKey($tenantId)->delete());
        }
        $this->extraTenantIds = [];

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorProfile = null;
        $this->clearedTenantBridgeKey = false;
    }
}
