<?php

namespace Tests\Feature;

use App\Actions\MasterData\AuthorizeFdaPackagingForPartner;
use App\Actions\MasterData\CopyFdaWddLicensesToTenantSite;
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
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CopyFdaWddLicensesToTenantSiteTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    /** @var list<int> */
    private array $tenantProductIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $packagingIds = [];

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function manufacturer_site_copies_zero_wdd_licenses(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR ATP MFR');
        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'fei_number' => 'SSORATPMFR1',
            'name' => 'SSOR ATP Plant',
            'firm_name' => 'SSOR ATP Plant',
            'address_fingerprint' => AddressFingerprint::make('1 Atp St', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);

        $this->initializeDemo2Tenant();

        $partner = $this->tenantPartner($org, PartnerType::Manufacturer);
        $site = $this->tenantSite($partner, [
            'fda_establishment_id' => $establishment->id,
            'name' => 'SSOR ATP Plant Site',
        ]);

        $copied = app(CopyFdaWddLicensesToTenantSite::class)->handle($site);

        $this->assertSame(0, $copied);
        $this->assertSame(0, AtpLicense::query()->where('site_id', $site->id)->count());
    }

    #[Test]
    public function manufacturer_site_with_wdd_facility_copies_licenses(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR ATP MFR WDD');
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR ATP Mfr DC',
            'facility_name' => 'SSOR ATP Mfr DC',
            'address_fingerprint' => AddressFingerprint::make('9 Atp St', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);

        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-ATP-MFR-WDD',
            'jurisdiction' => 'TX',
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);

        $this->initializeDemo2Tenant();

        $partner = $this->tenantPartner($org, PartnerType::Manufacturer);
        $site = $this->tenantSite($partner, [
            'fda_wdd_facility_id' => $facility->id,
            'name' => 'SSOR ATP Mfr WDD Site',
        ]);

        $copied = app(CopyFdaWddLicensesToTenantSite::class)->handle($site);

        $this->assertSame(1, $copied);
        $this->assertSame(1, AtpLicense::query()->where('site_id', $site->id)->count());
    }

    #[Test]
    public function wholesaler_copies_active_licenses_and_skips_expired_or_delisted(): void
    {
        $org = $this->organization(PartnerType::Wholesaler, 'SSOR ATP WDD');
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR ATP DC',
            'facility_name' => 'SSOR ATP DC',
            'address_fingerprint' => AddressFingerprint::make('2 Atp St', 'Dallas', 'TX', '75201', 'US'),
            'is_active' => true,
        ]);

        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-ATP-ACTIVE',
            'jurisdiction' => 'TX',
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);
        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-ATP-EXPIRED',
            'jurisdiction' => 'TX',
            'expiration_date' => now()->subDay(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);
        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-ATP-DELISTED',
            'jurisdiction' => 'TX',
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => false,
        ]);

        $this->initializeDemo2Tenant();

        $partner = $this->tenantPartner($org, PartnerType::Wholesaler);
        $site = $this->tenantSite($partner, [
            'fda_wdd_facility_id' => $facility->id,
            'name' => 'SSOR ATP DC Site',
        ]);

        $copied = app(CopyFdaWddLicensesToTenantSite::class)->handle($site);

        $this->assertSame(1, $copied);
        $this->assertDatabaseHas('atp_licenses', [
            'site_id' => $site->id,
            'license_number' => 'SSOR-ATP-ACTIVE',
            'is_active' => 1,
        ]);
        $this->assertDatabaseMissing('atp_licenses', [
            'site_id' => $site->id,
            'license_number' => 'SSOR-ATP-EXPIRED',
        ]);
        $this->assertDatabaseMissing('atp_licenses', [
            'site_id' => $site->id,
            'license_number' => 'SSOR-ATP-DELISTED',
        ]);
    }

    #[Test]
    public function three_pl_facility_copies_the_facility_type(): void
    {
        $org = $this->organization(PartnerType::Logistics3pl, 'SSOR ATP 3PL');
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::ThreePl,
            'name' => 'SSOR ATP 3PL DC',
            'facility_name' => 'SSOR ATP 3PL DC',
            'address_fingerprint' => AddressFingerprint::make('3 Atp St', 'Houston', 'TX', '77001', 'US'),
            'is_active' => true,
        ]);

        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-ATP-3PL',
            'jurisdiction' => 'TX',
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);

        $this->initializeDemo2Tenant();

        $partner = $this->tenantPartner($org, PartnerType::Logistics3pl);
        $site = $this->tenantSite($partner, [
            'fda_wdd_facility_id' => $facility->id,
            'name' => 'SSOR ATP 3PL Site',
        ]);

        $copied = app(CopyFdaWddLicensesToTenantSite::class)->handle($site);

        $this->assertSame(1, $copied);
        $this->assertDatabaseHas('atp_licenses', [
            'site_id' => $site->id,
            'license_number' => 'SSOR-ATP-3PL',
            'facility_type' => FacilityType::ThreePl->value,
            'is_active' => 1,
        ]);
    }

    /**
     * The phone number on the listing is how a receiving clerk reaches the facility
     * about a licence question, so it has to survive the copy.
     */
    #[Test]
    public function facility_contact_details_reach_the_tenant_license(): void
    {
        $org = $this->organization(PartnerType::Wholesaler, 'SSOR ATP CONTACT');
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR ATP Contact DC',
            'facility_name' => 'SSOR ATP Contact DC',
            'address_fingerprint' => AddressFingerprint::make('4 Atp St', 'Waco', 'TX', '76701', 'US'),
            'contact_person' => 'Dana Contact',
            'contact_email' => 'dana@example.test',
            'contact_phone' => '254-555-0143',
            'is_active' => true,
        ]);

        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-ATP-CONTACT',
            'jurisdiction' => 'TX',
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);

        $this->initializeDemo2Tenant();

        $partner = $this->tenantPartner($org, PartnerType::Wholesaler);
        $site = $this->tenantSite($partner, [
            'fda_wdd_facility_id' => $facility->id,
            'name' => 'SSOR ATP Contact Site',
        ]);

        $this->assertSame(1, app(CopyFdaWddLicensesToTenantSite::class)->handle($site));

        $license = AtpLicense::query()
            ->where('site_id', $site->id)
            ->where('license_number', 'SSOR-ATP-CONTACT')
            ->firstOrFail();

        $this->assertSame('Dana Contact', $license->facility_contact_person);
        $this->assertSame('dana@example.test', $license->facility_contact_email);
        $this->assertSame('254-555-0143', $license->facility_contact_phone);
    }

    #[Test]
    public function packaging_gtin_authorize_creates_tenant_sku(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR ATP PKG');
        FdaProduct::query()->create([
            'product_id' => 'SSOR-ATP-PKG',
            'product_ndc' => '88885-401',
            'name' => 'SSOR ATP Package',
            'fda_organization_id' => $org->id,
            'is_active' => true,
        ]);
        $listing = FdaProduct::query()->where('product_id', 'SSOR-ATP-PKG')->firstOrFail();
        $this->productIds[] = $listing->id;

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => '88885-401-01',
            'gtin' => '88885000000017',
            'ndc11' => '88885040101',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        $this->initializeDemo2Tenant();

        $wholesaler = $this->tenantPartner($org, PartnerType::Wholesaler, 'SSOR ATP Recv');
        $result = app(AuthorizeFdaPackagingForPartner::class)->handle($wholesaler, $packaging);

        $this->assertSame(1, $result['added']);
        $this->assertNotNull($result['product_id']);
        $this->tenantProductIds[] = (int) $result['product_id'];

        $product = Product::query()->findOrFail($result['product_id']);
        $this->assertSame('88885000000017', $product->gtin);
        $this->assertSame($packaging->id, $product->fda_product_packaging_id);
        $this->assertSame($listing->id, $product->fda_product_id);
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

    private function tenantPartner(FdaOrganization $org, PartnerType $type, ?string $name = null): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => $name ?? $org->name.' Tenant',
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => $type,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        return $partner;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tenantSite(TradingPartner $partner, array $attributes): Site
    {
        $site = Site::query()->create(array_merge([
            'trading_partner_id' => $partner->id,
            'country_code' => 'US',
            'is_active' => true,
        ], $attributes));
        $this->tenantSiteIds[] = $site->id;

        return $site;
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
        }

        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
        }
        if ($this->productIds !== []) {
            FdaProduct::query()->whereIn('id', $this->productIds)->delete();
        }
        if ($this->orgIds !== []) {
            FdaWddLicense::query()->whereIn('fda_wdd_facility_id', function ($query): void {
                $query->select('id')->from('fda_wdd_facilities')->whereIn('fda_organization_id', $this->orgIds);
            })->delete();
            FdaWddFacility::query()->whereIn('fda_organization_id', $this->orgIds)->delete();
            FdaEstablishment::query()->whereIn('fda_organization_id', $this->orgIds)->delete();
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }
    }
}
