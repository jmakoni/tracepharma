<?php

namespace Tests\Unit\Support\Fda;

use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\AtpLicense;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Product;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\FdaTenantLink;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FdaTenantLinkTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const ORG = 'SSOR LINK ORG';

    private const FEI = 'SSORLINKFEI1';

    private const PRODUCT_ID = 'SSOR-LINK-PROD';

    private const PACKAGE_NDC = '88883-101-01';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $establishmentIds = [];

    /** @var list<int> */
    private array $facilityIds = [];

    /** @var list<int> */
    private array $licenseIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $packagingIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    /** @var list<int> */
    private array $tenantProductIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function organization_id_reads_fda_stamp_only(): void
    {
        $partner = new TradingPartner([
            'fda_organization_id' => 91,
        ]);

        $this->assertSame(91, FdaTenantLink::organizationId($partner));
    }

    #[Test]
    public function organization_id_is_null_without_fda_stamp(): void
    {
        $partner = new TradingPartner([
            'fda_organization_id' => null,
        ]);

        $this->assertNull(FdaTenantLink::organizationId($partner));
    }

    #[Test]
    public function establishment_id_is_not_treated_as_a_wdd_facility_id(): void
    {
        $site = new Site([
            'fda_establishment_id' => 44,
            'fda_wdd_facility_id' => null,
        ]);

        $this->assertSame(44, FdaTenantLink::establishmentId($site));
        $this->assertNull(FdaTenantLink::wddFacilityId($site));
    }

    #[Test]
    public function site_ids_read_fda_stamps_only(): void
    {
        $estSite = new Site(['fda_establishment_id' => 12]);
        $facSite = new Site(['fda_wdd_facility_id' => 34]);

        $this->assertSame(12, FdaTenantLink::establishmentId($estSite));
        $this->assertNull(FdaTenantLink::wddFacilityId($estSite));
        $this->assertSame(34, FdaTenantLink::wddFacilityId($facSite));
        $this->assertNull(FdaTenantLink::establishmentId($facSite));
    }

    #[Test]
    public function packaging_id_reads_fda_stamp_only(): void
    {
        $product = new Product([
            'fda_product_packaging_id' => 55,
        ]);

        $this->assertSame(55, FdaTenantLink::packagingId($product));
    }

    #[Test]
    public function stamp_methods_write_fda_ids_only(): void
    {
        $org = $this->createOrganization();
        $establishment = $this->createEstablishment($org);
        $facility = $this->createFacility($org);
        $license = $this->createLicense($facility);
        [$listing, $packaging] = $this->createListingAndPackaging($org);

        $this->initializeDemo2Tenant();

        try {
            $partner = TradingPartner::query()->create([
                'name' => 'SSOR LINK Tenant Partner',
                'gln' => '8888300000014',
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = $partner->id;

            FdaTenantLink::stampPartner($partner, $org);
            $partner->refresh();
            $this->assertSame($org->id, $partner->fda_organization_id);

            $estSite = Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'SSOR LINK Plant',
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantSiteIds[] = $estSite->id;

            FdaTenantLink::stampSiteFromEstablishment($estSite, $establishment);
            $estSite->refresh();
            $this->assertSame($establishment->id, $estSite->fda_establishment_id);
            $this->assertNull($estSite->fda_wdd_facility_id);

            $facSite = Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'SSOR LINK DC',
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantSiteIds[] = $facSite->id;

            FdaTenantLink::stampSiteFromWddFacility($facSite, $facility);
            $facSite->refresh();
            $this->assertSame($facility->id, $facSite->fda_wdd_facility_id);
            $this->assertNull($facSite->fda_establishment_id);

            $tenantLicense = AtpLicense::query()->create([
                'site_id' => $facSite->id,
                'facility_type' => FacilityType::Wdd,
                'license_number' => 'SSOR-LINK-TENANT-LIC',
                'license_state' => 'TX',
                'reporting_year' => (int) now()->year,
                'is_active' => true,
            ]);

            FdaTenantLink::stampLicense($tenantLicense, $license);
            $tenantLicense->refresh();
            $this->assertSame($license->id, $tenantLicense->fda_wdd_license_id);

            $product = Product::query()->create([
                'name' => 'SSOR LINK Tenant SKU',
                'gtin' => '88883000000017',
                'is_active' => true,
            ]);
            $this->tenantProductIds[] = $product->id;

            FdaTenantLink::stampProduct($product, $listing, $packaging);
            $product->refresh();
            $this->assertSame($listing->id, $product->fda_product_id);
            $this->assertSame($packaging->id, $product->fda_product_packaging_id);
        } finally {
            $this->cleanupTenant();
        }
    }

    private function createOrganization(): FdaOrganization
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR Link Org',
            'canonical_name' => self::ORG,
            'name' => 'SSOR Link Org',
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        return $org;
    }

    private function createEstablishment(FdaOrganization $org): FdaEstablishment
    {
        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'fei_number' => self::FEI,
            'name' => 'SSOR LINK Plant',
            'firm_name' => 'SSOR LINK Plant',
            'address_fingerprint' => AddressFingerprint::make('1 Link St', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = $establishment->id;

        return $establishment;
    }

    private function createFacility(FdaOrganization $org): FdaWddFacility
    {
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR LINK DC',
            'facility_name' => 'SSOR LINK DC',
            'address_fingerprint' => AddressFingerprint::make('2 Link St', 'Dallas', 'TX', '75201', 'US'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = $facility->id;

        return $facility;
    }

    private function createLicense(FdaWddFacility $facility): FdaWddLicense
    {
        $license = FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-LINK-WDD',
            'jurisdiction' => 'TX',
            'is_active' => true,
        ]);
        $this->licenseIds[] = $license->id;

        return $license;
    }

    /**
     * @return array{0: FdaProduct, 1: FdaProductPackaging}
     */
    private function createListingAndPackaging(FdaOrganization $org): array
    {
        $listing = FdaProduct::query()->create([
            'product_id' => self::PRODUCT_ID,
            'product_ndc' => '88883-101',
            'name' => 'SSOR Link Product',
            'fda_organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->productIds[] = $listing->id;

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => self::PACKAGE_NDC,
            'gtin' => '88883000000017',
            'ndc11' => '88883010101',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        return [$listing, $packaging];
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

        if ($this->tenantSiteIds !== []) {
            AtpLicense::query()->whereIn('site_id', $this->tenantSiteIds)->delete();
            Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
        }

        if ($this->tenantPartnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
        }

        tenancy()->end();
        $this->tenantProductIds = [];
        $this->tenantSiteIds = [];
        $this->tenantPartnerIds = [];
    }

    private function cleanup(): void
    {
        $this->cleanupTenant();

        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
        }

        if ($this->productIds !== []) {
            FdaProduct::query()->whereIn('id', $this->productIds)->delete();
        }

        if ($this->licenseIds !== []) {
            FdaWddLicense::query()->whereIn('id', $this->licenseIds)->delete();
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
    }
}
