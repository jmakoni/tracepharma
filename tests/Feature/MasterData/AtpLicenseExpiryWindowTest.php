<?php

namespace Tests\Feature\MasterData;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\AtpLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\MasterData\AtpLicenseExpiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AtpLicenseExpiryWindowTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $tenantSiteIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $licenseIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function expiring_within_90_days_excludes_already_expired_licenses(): void
    {
        $this->initializeDemo2Tenant();

        $licenses = $this->createLicenses([
            'EXPIRED' => now()->subDay(),
            'TODAY' => now(),
            'SOON' => now()->addDays(30),
            'EDGE' => now()->addDays(90),
            'LATER' => now()->addDays(91),
            'UNKNOWN' => null,
        ]);

        $expiring = AtpLicenseExpiry::expiringSoon($this->licenseQuery())
            ->pluck('license_number')
            ->all();

        $this->assertEqualsCanonicalizing(
            [$licenses['TODAY'], $licenses['SOON'], $licenses['EDGE']],
            $expiring,
        );
    }

    #[Test]
    public function each_license_falls_in_exactly_one_expiry_window(): void
    {
        $this->initializeDemo2Tenant();

        $licenses = $this->createLicenses([
            'EXPIRED' => now()->subDay(),
            'SOON' => now()->addDays(30),
            'LATER' => now()->addDays(91),
            'UNKNOWN' => null,
        ]);

        $this->assertSame(
            [$licenses['EXPIRED']],
            AtpLicenseExpiry::expired($this->licenseQuery())->pluck('license_number')->all(),
        );

        $this->assertSame(
            [$licenses['SOON']],
            AtpLicenseExpiry::expiringSoon($this->licenseQuery())->pluck('license_number')->all(),
        );

        $this->assertSame(
            [$licenses['UNKNOWN']],
            AtpLicenseExpiry::unknownExpiry($this->licenseQuery())->pluck('license_number')->all(),
        );
    }

    #[Test]
    public function fda_wdd_license_identity_includes_the_facility(): void
    {
        $this->assertTrue(Schema::hasIndex(
            'fda_wdd_licenses',
            ['fda_wdd_facility_id', 'jurisdiction', 'license_number'],
            'unique',
        ));

        $this->assertFalse(Schema::hasIndex(
            'fda_wdd_licenses',
            ['jurisdiction', 'license_number'],
            'unique',
        ));
    }

    /**
     * @param  array<string, Carbon|null>  $expirations
     * @return array<string, string>
     */
    private function createLicenses(array $expirations): array
    {
        $partner = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 Expiry Partner '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = (int) $partner->id;

        $site = Site::query()->create([
            'trading_partner_id' => $partner->id,
            'name' => 'SSOR CUT2 Expiry Site',
            'is_headquarters' => true,
            'country_code' => 'US',
            'is_active' => true,
            'is_organization_facility' => false,
        ]);
        $this->tenantSiteIds[] = (int) $site->id;

        $numbers = [];

        foreach ($expirations as $key => $expiration) {
            $number = 'SSOR-CUT2-EXPIRY-'.$key;

            $license = AtpLicense::query()->create([
                'site_id' => $site->id,
                'facility_type' => FacilityType::Wdd,
                'license_number' => $number,
                'license_state' => 'TX',
                'license_expiration_date' => $expiration?->toDateString(),
                'reporting_year' => (int) now()->year,
                'is_active' => true,
            ]);
            $this->licenseIds[] = (int) $license->id;

            $numbers[$key] = $number;
        }

        return $numbers;
    }

    /**
     * @return Builder<AtpLicense>
     */
    private function licenseQuery(): Builder
    {
        return AtpLicense::query()
            ->whereIn('id', $this->licenseIds)
            ->orderBy('license_expiration_date');
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
        if (tenancy()->initialized) {
            if ($this->licenseIds !== []) {
                AtpLicense::query()->whereIn('id', $this->licenseIds)->delete();
            }
            if ($this->tenantSiteIds !== []) {
                Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
            }
            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }
            tenancy()->end();
        }

        $this->licenseIds = [];
        $this->tenantSiteIds = [];
        $this->tenantPartnerIds = [];
    }
}
