<?php

namespace Tests\Feature;

use App\Actions\MasterData\AddFdaPackagesToTradingPartner;
use App\Actions\MasterData\AuthorizeFdaPackagingForPartner;
use App\Actions\MasterData\CreateHqSiteForTradingPartner;
use App\Actions\MasterData\ReconcilePendingManufacturerAuthorizations;
use App\Enums\AuthorizationStatus;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Jobs\SyncTenantAtpLicensesFromFda;
use App\Models\AtpLicense;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Product;
use App\Models\ProductPackagingLink;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Fda\AddressFingerprint;
use App\Support\Gs1\Gtin;
use App\Support\MasterData\PartnerSiteCreate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FdaRewireLeftoverBugfixTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $orgIds = [];

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
    public function fda_case_gtin_creates_a_second_product_instead_of_reusing_the_unit(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR LF CASE');
        $listing = $this->listing($org, 'SSOR-LF-CASE', '88887-501', 'SSOR LF Case Pack');
        $unitGtin = Gtin::fromPackageNdc('8888750101');
        $this->assertNotNull($unitGtin);
        $caseBody = '1038888750101';
        $caseGtin = $caseBody.Gtin::checkDigit($caseBody);

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => '88887-501-01',
            'gtin' => $unitGtin,
            'ndc11' => '88887050101',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        $this->initializeDemo2Tenant();

        $wholesaler = $this->tenantPartner($org, PartnerType::Wholesaler, 'SSOR LF Recv');
        $unitResult = app(AuthorizeFdaPackagingForPartner::class)->handle($wholesaler, $packaging);
        $this->tenantProductIds[] = (int) $unitResult['product_id'];

        $caseResult = app(AuthorizeFdaPackagingForPartner::class)->handle(
            $wholesaler,
            $packaging,
            ['units_per_case' => 12],
            false,
            $caseGtin,
        );
        $this->tenantProductIds[] = (int) $caseResult['product_id'];

        $this->assertSame(1, $caseResult['added']);
        $this->assertNotSame($unitResult['product_id'], $caseResult['product_id']);

        $case = Product::query()->findOrFail($caseResult['product_id']);
        $this->assertSame($caseGtin, $case->gtin);
        $this->assertSame($packaging->id, $case->fda_product_packaging_id);
    }

    #[Test]
    public function wholesaler_hq_create_copies_fda_licenses_from_wdd_facility(): void
    {
        $org = $this->organization(PartnerType::Wholesaler, 'SSOR LF HQ');
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR LF HQ DC',
            'facility_name' => 'SSOR LF HQ DC',
            'address_fingerprint' => AddressFingerprint::make('4 Lf St', 'Dallas', 'TX', '75201', 'US'),
            'is_headquarters' => true,
            'is_active' => true,
        ]);

        $wddLicense = FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-LF-FDA-HQ',
            'jurisdiction' => 'TX',
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);

        $this->initializeDemo2Tenant();

        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => 'SSOR LF WDD Tenant',
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        $site = app(CreateHqSiteForTradingPartner::class)->handle($partner);
        $this->assertNotNull($site);
        $this->tenantSiteIds[] = $site->id;

        $site->refresh();
        $this->assertSame($facility->id, $site->fda_wdd_facility_id);
        $this->assertDatabaseHas('atp_licenses', [
            'site_id' => $site->id,
            'license_number' => 'SSOR-LF-FDA-HQ',
            'fda_wdd_license_id' => $wddLicense->id,
            'is_active' => 1,
        ]);

        $eligible = (new SyncTenantAtpLicensesFromFda($this->initializeDemo2Tenant()))->eligibleSites();
        $this->assertTrue($eligible->contains(fn (Site $row): bool => (int) $row->id === (int) $site->id));
    }

    #[Test]
    public function manufacturer_can_import_a_same_org_wdd_facility(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR LF CAT MFR');

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR LF Cat Plant Fac',
            'facility_name' => 'SSOR LF Cat Plant Fac',
            'address_fingerprint' => AddressFingerprint::make('5 Lf St', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);

        $this->initializeDemo2Tenant();

        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => 'SSOR LF Cat Mfr Tenant',
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Manufacturer,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        $this->assertNull(PartnerSiteCreate::wddFacilityProblemFor($partner, $facility));
    }

    #[Test]
    public function reconcile_upgrades_fda_stamped_pending_products(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR LF REC');
        $listing = $this->listing($org, 'SSOR-LF-REC', '88887-502', 'SSOR LF Rec SKU');
        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => '88887-502-01',
            'gtin' => Gtin::fromPackageNdc('8888750201'),
            'ndc11' => '88887050201',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        $this->initializeDemo2Tenant();

        $wholesaler = TradingPartner::query()->create([
            'name' => 'SSOR LF Rec Recv',
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $wholesaler->id;

        $product = Product::query()->create([
            'name' => 'SSOR LF Rec SKU',
            'gtin' => $packaging->gtin,
            'fda_product_id' => $listing->id,
            'fda_product_packaging_id' => $packaging->id,
            'is_active' => true,
        ]);
        $this->tenantProductIds[] = $product->id;

        $wholesaler->products()->attach($product->id, [
            'authorization_status' => AuthorizationStatus::PendingManufacturer->value,
            'authorized_at' => now(),
        ]);

        $manufacturer = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => 'SSOR LF Rec Mfr',
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Manufacturer,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $manufacturer->id;

        $result = app(ReconcilePendingManufacturerAuthorizations::class)->handle($manufacturer);

        $this->assertSame(1, $result['products_linked']);
        $this->assertSame(1, $result['pivots_authorized']);
        $this->assertSame(
            AuthorizationStatus::Authorized->value,
            $wholesaler->fresh()->products()->where('products.id', $product->id)->first()?->pivot->authorization_status,
        );
    }

    #[Test]
    public function mixed_fda_package_batch_authorizes_multiple_skus(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR LF MIX');
        $listing = $this->listing($org, 'SSOR-LF-MIX', '88887-503', 'SSOR LF Mix');
        $unitGtin = Gtin::fromPackageNdc('8888750301');

        $primaryPackaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => '88887-503-01',
            'gtin' => $unitGtin,
            'ndc11' => '88887050301',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $primaryPackaging->id;

        $secondaryListing = $this->listing($org, 'SSOR-LF-MIX-2', '88887-504', 'SSOR LF Mix Secondary');
        $secondaryGtin = Gtin::fromPackageNdc('8888750401');
        $secondaryPackaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $secondaryListing->id,
            'package_ndc' => '88887-504-01',
            'gtin' => $secondaryGtin,
            'ndc11' => '88887050401',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $secondaryPackaging->id;

        $this->initializeDemo2Tenant();

        $wholesaler = $this->tenantPartner($org, PartnerType::Wholesaler, 'SSOR LF Mix Recv');
        $result = app(AddFdaPackagesToTradingPartner::class)->handle(
            $wholesaler,
            [$primaryPackaging->id, $secondaryPackaging->id],
        );

        $this->assertGreaterThanOrEqual(2, $result['added'] + $result['attached']);
        $this->assertTrue(Product::query()->where('gtin', $unitGtin)->exists());
        $this->assertTrue(Product::query()->where('gtin', $secondaryGtin)->exists());

        Product::query()->whereIn('gtin', [$unitGtin, $secondaryGtin])->pluck('id')
            ->each(function (int $id): void {
                $this->tenantProductIds[] = $id;
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
                ProductPackagingLink::query()
                    ->whereIn('parent_product_id', $this->tenantProductIds)
                    ->orWhereIn('child_product_id', $this->tenantProductIds)
                    ->delete();
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
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }
    }
}
