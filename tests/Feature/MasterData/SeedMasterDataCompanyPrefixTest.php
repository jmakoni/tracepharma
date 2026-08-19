<?php

namespace Tests\Feature\MasterData;

use App\Actions\Demo\SeedMasterData;
use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\TenantSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeedMasterDataCompanyPrefixTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function seed_master_data_sets_company_prefix_when_blank(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $settings = TenantSettings::forTenant($tenant);
            $this->priorCompanyPrefix = $settings->companyPrefix();

            $tenant->forceFill(['company_prefix' => null])->save();

            app(SeedMasterData::class)->handle();

            $prefix = TenantSettings::forTenant($tenant->fresh())->companyPrefix();

            $this->assertNotNull($prefix);
            $this->assertGreaterThanOrEqual(6, strlen($prefix));
            TenantSettings::assertValidCompanyPrefix($prefix, TenantSettings::forTenant($tenant->fresh())->gln());
        } finally {
            if (tenancy()->initialized) {
                TenantSettings::forTenant($tenant)->setCompanyPrefix($this->priorCompanyPrefix);
                $tenant->save();
            }
            tenancy()->end();
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
}
