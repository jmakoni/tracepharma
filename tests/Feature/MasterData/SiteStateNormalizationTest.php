<?php

namespace Tests\Feature\MasterData;

use App\Enums\TenantProfile;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteStateNormalizationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const GLN_PREFIX = '094231';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    #[Test]
    public function full_us_state_name_persists_as_postal_code(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->create([
                'name' => 'State Norm '.Str::lower(Str::random(6)),
                'gln' => $this->uniqueGln('01'),
                'street_address' => '150 Demo Street',
                'city' => 'Wheeling',
                'state' => 'Illinois',
                'zipcode' => '60090',
                'country_code' => 'US',
                'is_active' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $this->assertSame('IL', $site->fresh()->state);
        } finally {
            $this->cleanup();
        }
    }

    private function uniqueGln(string $serial2): string
    {
        $body = self::GLN_PREFIX.str_pad(substr(preg_replace('/\D+/', '', $serial2) ?? '0', 0, 6), 6, '0', STR_PAD_LEFT);

        return $body.Gtin::checkDigit($body);
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

        return tenant() instanceof Tenant ? tenant() : $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            foreach ($this->siteIds as $siteId) {
                Site::query()->whereKey($siteId)->delete();
            }
            $this->siteIds = [];
            tenancy()->end();
        }
    }
}
