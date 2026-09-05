<?php

namespace Tests\Unit\Support\Fda;

use App\Enums\TenantProfile;
use App\Models\Fda\FdaProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Fda\ScheduledProductPresence;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduledProductPresenceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $fdaProductIds = [];

    /** @var list<int> */
    private array $tenantProductIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function reports_highest_schedule_for_fda_linked_gtins_only(): void
    {
        $ciiGtin = '88884000001001';
        $civGtin = '88884000001002';

        $ciiListing = FdaProduct::query()->create([
            'product_id' => 'SSOR-SCHED-CII',
            'product_ndc' => '88884-501',
            'name' => 'SSOR Scheduled CII',
            'dea_schedule' => '2',
            'is_active' => true,
        ]);
        $this->fdaProductIds[] = $ciiListing->id;

        $civListing = FdaProduct::query()->create([
            'product_id' => 'SSOR-SCHED-CIV',
            'product_ndc' => '88884-502',
            'name' => 'SSOR Scheduled CIV',
            'dea_schedule' => 'CIV',
            'is_active' => true,
        ]);
        $this->fdaProductIds[] = $civListing->id;

        $this->initializeDemo2Tenant();

        try {
            $ciiProduct = Product::query()->create([
                'name' => 'SSOR Scheduled CII SKU',
                'gtin' => $ciiGtin,
                'fda_product_id' => $ciiListing->id,
                'is_active' => true,
            ]);
            $this->tenantProductIds[] = $ciiProduct->id;

            $civProduct = Product::query()->create([
                'name' => 'SSOR Scheduled CIV SKU',
                'gtin' => $civGtin,
                'fda_product_id' => $civListing->id,
                'is_active' => true,
            ]);
            $this->tenantProductIds[] = $civProduct->id;

            $result = ScheduledProductPresence::forGtins([$ciiGtin, $civGtin, '00000000000000']);

            $this->assertTrue($result['has_scheduled']);
            $this->assertSame('CII', $result['highest']);
        } finally {
            $this->cleanupTenant();
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

    private function cleanupTenant(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->tenantProductIds !== []) {
            Product::query()->whereIn('id', $this->tenantProductIds)->delete();
        }

        tenancy()->end();
        $this->tenantProductIds = [];
    }

    private function cleanup(): void
    {
        $this->cleanupTenant();

        if ($this->fdaProductIds !== []) {
            FdaProduct::query()->whereIn('id', $this->fdaProductIds)->delete();
        }
    }
}
