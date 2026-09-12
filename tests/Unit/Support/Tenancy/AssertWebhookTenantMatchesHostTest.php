<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Tenancy;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\Tenancy\AssertWebhookTenantMatchesHost;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AssertWebhookTenantMatchesHostTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function allows_path_tenant_when_no_host_tenancy_is_active(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        AssertWebhookTenantMatchesHost::assert((string) Str::uuid());

        $this->assertFalse(tenancy()->initialized);
    }

    #[Test]
    public function allows_when_host_tenant_matches_path_tenant_id(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        tenancy()->initialize($tenant);

        AssertWebhookTenantMatchesHost::assert(self::DEMO2_TENANT_ID);

        $this->assertTrue(tenancy()->initialized);
        $this->assertSame(self::DEMO2_TENANT_ID, (string) tenant()->getKey());
    }

    #[Test]
    public function aborts_404_when_host_tenant_differs_from_path_tenant_id(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        tenancy()->initialize($tenant);

        $otherId = (string) Str::uuid();

        $this->expectException(NotFoundHttpException::class);

        AssertWebhookTenantMatchesHost::assert($otherId);
    }

    private function ensureDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Tenant',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
        }

        return $tenant;
    }
}
