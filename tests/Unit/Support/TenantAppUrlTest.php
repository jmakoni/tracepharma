<?php

namespace Tests\Unit\Support;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\TenantAppUrl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantAppUrlTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    #[Test]
    public function exception_cta_uses_the_tenant_host_not_app_url(): void
    {
        config(['app.url' => 'https://admin2.localhost']);

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

        $url = TenantAppUrl::exception(42, $tenant);

        $this->assertSame('https://'.self::DEMO2_DOMAIN.'/exceptions/42', $url);
        $this->assertStringNotContainsString('admin2.localhost', $url);
        $this->assertSame('https://'.self::DEMO2_DOMAIN.'/exceptions', TenantAppUrl::forPath('/exceptions', $tenant));
    }
}
