<?php

namespace Tests\Feature\MasterData;

use App\Actions\MasterData\BackfillTenantFdaStampsFromCatalog;
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
use App\Support\Gs1\Gtin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillTenantFdaStampsFromCatalogTest extends TestCase
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

    /** @var list<int> */
    private array $tenantLicenseIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function historical_partner_fk_migration_does_not_load_deleted_catalog_eloquent(): void
    {
        $this->assertFalse(class_exists(\App\Models\Catalog\CatalogTradingPartner::class));

        $source = (string) file_get_contents(database_path(
            'migrations/2026_07_29_250001_add_partner_fks_drop_labeler_manufacturer_strings.php'
        ));

        $this->assertStringNotContainsString('App\\Models\\Catalog\\CatalogTradingPartner', $source);
        $this->assertStringContainsString("DB::table('catalog_trading_partners')", $source);
        $this->assertStringContainsString("Schema::hasTable('catalog_trading_partners')", $source);
    }

    #[Test]
    public function manufacturer_site_prefers_establishment_when_gln_exists_on_both(): void
    {
        $suffix = $this->suffix();
        $gln = $this->uniqueGln('11');
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR BF MFR '.$suffix);
        $establishment = $this->establishment($org, $gln, 'SSOR BF Est '.$suffix, '1 Est St');
        $this->wddFacility($org, $gln, 'SSOR BF Wdd '.$suffix, '2 Wdd St');

        $this->initializeDemo2Tenant();
        $this->ensureCatalogStampColumns();

        $partner = $this->tenantPartner(PartnerType::Manufacturer, 'SSOR BF Mfr Partner '.$suffix);
        $site = $this->tenantSite($partner, $gln, 'SSOR BF Mfr Site '.$suffix);

        $this->isolatedBackfill()->runSites();

        $site->refresh();
        $this->assertSame($establishment->id, $site->fda_establishment_id);
        $this->assertNull($site->fda_wdd_facility_id);
    }

    #[Test]
    public function organization_dock_is_not_stamped_from_wdd_gln(): void
    {
        $suffix = $this->suffix();
        $gln = $this->uniqueGln('12');
        $org = $this->organization(PartnerType::Wholesaler, 'SSOR BF ORG '.$suffix);
        $this->wddFacility($org, $gln, 'SSOR BF Org Wdd '.$suffix, '3 Org St');

        $this->initializeDemo2Tenant();
        $this->ensureCatalogStampColumns();

        $site = Site::query()->create([
            'trading_partner_id' => null,
            'is_organization_facility' => true,
            'name' => 'SSOR BF Org Dock '.$suffix,
            'code' => 'ORG-'.$suffix,
            'gln' => $gln,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantSiteIds[] = $site->id;

        $this->isolatedBackfill()->runSites();

        $site->refresh();
        $this->assertNull($site->fda_establishment_id);
        $this->assertNull($site->fda_wdd_facility_id);
    }

    #[Test]
    public function license_stamp_requires_the_sites_wdd_facility(): void
    {
        $suffix = $this->suffix();
        $glnA = $this->uniqueGln('13');
        $glnB = $this->uniqueGln('14');
        $org = $this->organization(PartnerType::Wholesaler, 'SSOR BF LIC '.$suffix);
        $facilityA = $this->wddFacility($org, $glnA, 'SSOR BF Lic A '.$suffix, '4 Lic A St');
        $facilityB = $this->wddFacility($org, $glnB, 'SSOR BF Lic B '.$suffix, '5 Lic B St');
        $licenseA = $this->wddLicense($facilityA, 'TX', 'SSOR-LIC-'.$suffix);
        $licenseB = $this->wddLicense($facilityB, 'TX', 'SSOR-LIC-'.$suffix);

        $this->initializeDemo2Tenant();
        $this->ensureCatalogStampColumns();

        $partner = $this->tenantPartner(PartnerType::Wholesaler, 'SSOR BF Lic Partner '.$suffix);
        $site = $this->tenantSite($partner, $glnA, 'SSOR BF Lic Site '.$suffix, [
            'fda_wdd_facility_id' => $facilityA->id,
        ]);
        $tenantLicense = AtpLicense::query()->create([
            'site_id' => $site->id,
            'facility_type' => FacilityType::Wdd,
            'license_number' => 'SSOR-LIC-'.$suffix,
            'license_state' => 'TX',
            'license_expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);
        $this->tenantLicenseIds[] = $tenantLicense->id;

        $this->isolatedBackfill()->runLicenses();

        $tenantLicense->refresh();
        $this->assertSame($licenseA->id, $tenantLicense->fda_wdd_license_id);
        $this->assertNotSame($licenseB->id, $tenantLicense->fda_wdd_license_id);
    }

    #[Test]
    public function existing_packaging_stamp_does_not_pull_a_different_fda_product(): void
    {
        $suffix = $this->suffix();
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR BF PKG '.$suffix);
        $listingA = $this->listing($org, 'SSOR-PKG-A-'.$suffix, '88881-101', 'SSOR Pkg A '.$suffix);
        $listingB = $this->listing($org, 'SSOR-PKG-B-'.$suffix, '88881-202', 'SSOR Pkg B '.$suffix);
        $gtinA = Gtin::fromPackageNdc('8888110101');
        $gtinB = Gtin::fromPackageNdc('8888120202');
        $this->assertNotNull($gtinA);
        $this->assertNotNull($gtinB);
        $packagingA = $this->packaging($listingA, $gtinA, '88881101011', '88881-101-01');
        $packagingB = $this->packaging($listingB, $gtinB, '88881202022', '88881-202-02');

        $this->initializeDemo2Tenant();
        $this->ensureCatalogStampColumns();

        $product = Product::query()->create([
            'name' => 'SSOR BF Split '.$suffix,
            'gtin' => $packagingB->gtin,
            'fda_product_packaging_id' => $packagingA->id,
            'fda_product_id' => null,
            'is_active' => true,
        ]);
        $this->tenantProductIds[] = $product->id;

        $this->isolatedBackfill()->runProducts();

        $product->refresh();
        $this->assertSame($packagingA->id, $product->fda_product_packaging_id);
        $this->assertSame($listingA->id, $product->fda_product_id);
        $this->assertNotSame($listingB->id, $product->fda_product_id);
    }

    #[Test]
    public function manufacturer_wdd_only_gln_stamps_the_site_but_not_the_license(): void
    {
        $suffix = $this->suffix();
        $gln = $this->uniqueGln('15');
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR BF MFR WDD '.$suffix);
        $facility = $this->wddFacility($org, $gln, 'SSOR BF Mfr Wdd '.$suffix, '6 Mfr Wdd St');
        $fdaLicense = $this->wddLicense($facility, 'TX', 'SSOR-MFR-WDD-'.$suffix);

        $this->initializeDemo2Tenant();
        $this->ensureCatalogStampColumns();

        $partner = $this->tenantPartner(PartnerType::Manufacturer, 'SSOR BF Mfr Wdd Partner '.$suffix);
        $site = $this->tenantSite($partner, $gln, 'SSOR BF Mfr Wdd Site '.$suffix);
        $tenantLicense = AtpLicense::query()->create([
            'site_id' => $site->id,
            'facility_type' => FacilityType::Wdd,
            'license_number' => 'SSOR-MFR-WDD-'.$suffix,
            'license_state' => 'TX',
            'license_expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);
        $this->tenantLicenseIds[] = $tenantLicense->id;

        $action = $this->isolatedBackfill();
        $action->runSites();
        $site->refresh();
        $this->assertSame($facility->id, $site->fda_wdd_facility_id);
        $this->assertNull($site->fda_establishment_id);

        $action->runLicenses();
        $tenantLicense->refresh();
        $this->assertNull($tenantLicense->fda_wdd_license_id);
        $this->assertNotSame($fdaLicense->id, $tenantLicense->fda_wdd_license_id);
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

    private function ensureCatalogStampColumns(): void
    {
        $this->addNullableUnsignedBigInt('trading_partners', 'catalog_trading_partner_id');
        $this->addNullableUnsignedBigInt('sites', 'catalog_site_id');
        $this->addNullableUnsignedBigInt('products', 'catalog_product_id');
        $this->addNullableUnsignedBigInt('atp_licenses', 'catalog_atp_license_id');
    }

    private function dropCatalogStampColumns(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $this->dropColumnIfPresent('trading_partners', 'catalog_trading_partner_id');
        $this->dropColumnIfPresent('sites', 'catalog_site_id');
        $this->dropColumnIfPresent('products', 'catalog_product_id');
        $this->dropColumnIfPresent('atp_licenses', 'catalog_atp_license_id');
    }

    private function addNullableUnsignedBigInt(string $table, string $column): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->unsignedBigInteger($column)->nullable();
        });
    }

    private function dropColumnIfPresent(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }

    private function organization(PartnerType $type, string $name): FdaOrganization
    {
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => strtoupper($name),
            'name' => $name,
            'partner_type' => $type,
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        return $org;
    }

    private function establishment(FdaOrganization $org, string $gln, string $name, string $street): FdaEstablishment
    {
        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'firm_name' => $name,
            'name' => $name,
            'gln' => $gln,
            'address_fingerprint' => AddressFingerprint::make($street, 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = $establishment->id;

        return $establishment;
    }

    private function wddFacility(FdaOrganization $org, string $gln, string $name, string $street): FdaWddFacility
    {
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => $name,
            'name' => $name,
            'gln' => $gln,
            'address_fingerprint' => AddressFingerprint::make($street, 'Dallas', 'TX', '75201', 'US'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = $facility->id;

        return $facility;
    }

    private function wddLicense(FdaWddFacility $facility, string $state, string $number): FdaWddLicense
    {
        $license = FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => $number,
            'jurisdiction' => $state,
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);
        $this->licenseIds[] = $license->id;

        return $license;
    }

    private function listing(FdaOrganization $org, string $productId, string $productNdc, string $name): FdaProduct
    {
        FdaProduct::query()->create([
            'product_id' => $productId,
            'product_ndc' => $productNdc,
            'name' => $name,
            'fda_organization_id' => $org->id,
            'is_active' => true,
        ]);
        $listing = FdaProduct::query()->where('product_id', $productId)->firstOrFail();
        $this->productIds[] = $listing->id;

        return $listing;
    }

    private function packaging(
        FdaProduct $listing,
        string $gtin,
        string $ndc11,
        string $packageNdc,
    ): FdaProductPackaging {
        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'gtin' => $gtin,
            'ndc11' => $ndc11,
            'package_ndc' => $packageNdc,
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        return $packaging;
    }

    private function tenantPartner(PartnerType $type, string $name): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => $name,
            'gln' => $this->uniqueGln('21'),
            'partner_type' => $type,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        return $partner;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function tenantSite(TradingPartner $partner, string $gln, string $name, array $overrides = []): Site
    {
        $site = Site::query()->create(array_merge([
            'trading_partner_id' => $partner->id,
            'is_organization_facility' => false,
            'name' => $name,
            'code' => 'SITE-'.$this->suffix(),
            'gln' => $gln,
            'country_code' => 'US',
            'is_active' => true,
        ], $overrides));
        $this->tenantSiteIds[] = $site->id;

        return $site;
    }

    private function suffix(): string
    {
        return substr((string) str()->ulid(), -6);
    }

    private function uniqueGln(string $prefix): string
    {
        return str_pad($prefix, 2, '0', STR_PAD_LEFT).fake()->unique()->numerify('###########');
    }

    private function isolatedBackfill(): IsolatedBackfillTenantFdaStampsFromCatalog
    {
        $action = new IsolatedBackfillTenantFdaStampsFromCatalog;
        $action->partnerIds = $this->tenantPartnerIds;
        $action->siteIds = $this->tenantSiteIds;
        $action->productIds = $this->tenantProductIds;
        $action->licenseIds = $this->tenantLicenseIds;

        return $action;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->tenantLicenseIds !== []) {
                AtpLicense::query()->whereIn('id', $this->tenantLicenseIds)->delete();
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
            $this->dropCatalogStampColumns();
            tenancy()->end();
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
        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
        }
        if ($this->productIds !== []) {
            FdaProduct::query()->whereIn('id', $this->productIds)->delete();
        }
        if ($this->orgIds !== []) {
            FdaWddFacility::query()->whereIn('fda_organization_id', $this->orgIds)->delete();
            FdaEstablishment::query()->whereIn('fda_organization_id', $this->orgIds)->delete();
            FdaProduct::query()->whereIn('fda_organization_id', $this->orgIds)->delete();
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }
    }
}

/**
 * Exposes individual backfills and constrains them to fixture IDs.
 * Does not drop tenant catalog_*_id columns.
 */
final class IsolatedBackfillTenantFdaStampsFromCatalog extends BackfillTenantFdaStampsFromCatalog
{
    /** @var list<int> */
    public array $partnerIds = [];

    /** @var list<int> */
    public array $siteIds = [];

    /** @var list<int> */
    public array $productIds = [];

    /** @var list<int> */
    public array $licenseIds = [];

    public function runSites(): void
    {
        $counts = ['partners' => 0, 'sites' => 0, 'products' => 0, 'licenses' => 0];
        $this->backfillSites($counts);
    }

    public function runProducts(): void
    {
        $counts = ['partners' => 0, 'sites' => 0, 'products' => 0, 'licenses' => 0];
        $this->backfillProducts($counts);
    }

    public function runLicenses(): void
    {
        $counts = ['partners' => 0, 'sites' => 0, 'products' => 0, 'licenses' => 0];
        $this->backfillLicenses($counts);
    }

    /**
     * @param  Builder<TradingPartner>  $query
     * @return Builder<TradingPartner>
     */
    protected function constrainPartners(Builder $query): Builder
    {
        return $this->constrainToIds($query, $this->partnerIds);
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    protected function constrainSites(Builder $query): Builder
    {
        return $this->constrainToIds($query, $this->siteIds);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    protected function constrainProducts(Builder $query): Builder
    {
        return $this->constrainToIds($query, $this->productIds);
    }

    /**
     * @param  Builder<AtpLicense>  $query
     * @return Builder<AtpLicense>
     */
    protected function constrainLicenses(Builder $query): Builder
    {
        return $this->constrainToIds($query, $this->licenseIds);
    }

    /**
     * @param  Builder<TradingPartner|Site|Product|AtpLicense>  $query
     * @param  list<int>  $ids
     * @return Builder<TradingPartner|Site|Product|AtpLicense>
     */
    private function constrainToIds(Builder $query, array $ids): Builder
    {
        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('id', $ids);
    }
}
