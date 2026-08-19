<?php

namespace Tests\Feature;

use App\Actions\MasterData\CreateHqSiteForTradingPartner;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Fda\AddressFingerprint;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateHqSiteForTradingPartnerTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $establishmentIds = [];

    /** @var list<int> */
    private array $facilityIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function creates_headquarters_site_for_wholesaler_with_fda_wdd_facility(): void
    {
        $gln = '06'.fake()->unique()->numerify('###########');
        $org = $this->createOrganization('SSOR CUT HQ Wholesaler', PartnerType::Wholesaler, $gln);

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR CUT HQ DC',
            'facility_name' => 'SSOR CUT HQ DC',
            'gln' => $gln,
            'street_address' => '100 Trace Way',
            'city' => 'Austin',
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'is_headquarters' => true,
            'address_fingerprint' => AddressFingerprint::make('100 Trace Way', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = $facility->id;

        $this->initializeDemo2Tenant();

        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => 'Acme Wholesaler',
            'partner_type' => PartnerType::Wholesaler,
            'street_address' => '100 Trace Way',
            'city' => 'Austin',
            'state' => 'TX',
            'zipcode' => '78701',
            'country_code' => 'US',
            'gln' => $gln,
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        $site = app(CreateHqSiteForTradingPartner::class)->handle($partner);

        $this->assertNotNull($site);
        $this->tenantSiteIds[] = $site->id;
        $this->assertTrue($site->is_headquarters);
        $this->assertSame('Acme Wholesaler - HQ Site', $site->name);
        $this->assertSame($partner->id, $site->trading_partner_id);
        $this->assertSame($this->facilityIds[0], $site->fda_wdd_facility_id);
        $this->assertSame('100 Trace Way', $site->street_address);
        $this->assertSame($gln, $site->gln);
    }

    #[Test]
    public function returns_existing_headquarters_site(): void
    {
        $gln = '06'.fake()->unique()->numerify('###########');
        $org = $this->createOrganization('SSOR CUT HQ Reuse', PartnerType::Manufacturer, $gln);

        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'fei_number' => 'SSORHQ1',
            'name' => 'SSOR CUT HQ Plant',
            'firm_name' => 'SSOR CUT HQ Plant',
            'gln' => $gln,
            'is_headquarters' => true,
            'address_fingerprint' => AddressFingerprint::make('1 Plant Rd', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = $establishment->id;

        $this->initializeDemo2Tenant();

        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => 'Reuse Partner',
            'partner_type' => PartnerType::Manufacturer,
            'gln' => $gln,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        $first = app(CreateHqSiteForTradingPartner::class)->handle($partner);
        $second = app(CreateHqSiteForTradingPartner::class)->handle($partner);

        $this->assertNotNull($first);
        $this->tenantSiteIds[] = $first->id;
        $this->assertTrue($first->is($second));
        $this->assertSame(1, Site::query()->where('trading_partner_id', $partner->id)->where('is_headquarters', true)->count());
    }

    private function createOrganization(string $name, PartnerType $type, string $gln): FdaOrganization
    {
        $suffix = uniqid();
        $org = FdaOrganization::query()->create([
            'original_name' => $name.' '.$suffix,
            'canonical_name' => strtoupper($name).' '.$suffix,
            'name' => $name.' '.$suffix,
            'partner_type' => $type,
            'gln' => $gln,
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        return $org;
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
            if ($this->tenantSiteIds !== []) {
                Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
            }
            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }
            tenancy()->end();
        }

        if ($this->facilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->facilityIds)->delete();
        }
        if ($this->establishmentIds !== []) {
            FdaEstablishment::query()->whereIn('id', $this->establishmentIds)->delete();
        }
        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        $this->orgIds = [];
        $this->establishmentIds = [];
        $this->facilityIds = [];
        $this->tenantPartnerIds = [];
        $this->tenantSiteIds = [];
    }
}
