<?php

namespace Tests\Feature;

use App\Actions\MasterData\AuthorizeFdaPackagingForPartner;
use App\Actions\MasterData\CreateHqSiteForTradingPartner;
use App\Enums\AuthorizationStatus;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\AtpLicense;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\ProductPackagingLink;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use App\Support\MasterData\MajorWholesalers;
use App\Support\MasterData\PartnerSiteCreate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FdaRewireBugfixTest extends TestCase
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
    public function case_gtin_override_creates_a_second_product_instead_of_reusing_the_unit(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR BF CASE');
        $listing = $this->listing($org, 'SSOR-BF-CASE', '88886-402', 'SSOR BF Case Pack');
        $unitGtin = Gtin::fromPackageNdc('8888640201');
        $this->assertNotNull($unitGtin);

        $caseBody = '1038888640201';
        $caseGtin = $caseBody.Gtin::checkDigit($caseBody);

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => '88886-402-01',
            'gtin' => $unitGtin,
            'ndc11' => '88886040201',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        $this->initializeDemo2Tenant();

        $wholesaler = $this->tenantPartner($org, PartnerType::Wholesaler, 'SSOR BF Recv');
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

        $unit = Product::query()->findOrFail($unitResult['product_id']);
        $case = Product::query()->findOrFail($caseResult['product_id']);

        $this->assertSame($unitGtin, $unit->gtin);
        $this->assertSame('88886040201', $unit->ndc11);
        $this->assertSame($caseGtin, $case->gtin);
        $this->assertNull($case->ndc11);

        $link = ProductPackagingLink::query()
            ->where('parent_product_id', $case->id)
            ->where('child_product_id', $unit->id)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame(12, $link->quantity);
    }

    #[Test]
    public function manufacturer_hq_create_does_not_auto_copy_atp_licenses(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR BF MFR HQ');

        $this->initializeDemo2Tenant();

        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => 'SSOR BF Mfr Tenant',
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Manufacturer,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $partner->id;

        $site = app(CreateHqSiteForTradingPartner::class)->handle($partner);
        $this->assertNotNull($site);
        $this->tenantSiteIds[] = $site->id;

        $this->assertSame(0, AtpLicense::query()->where('site_id', $site->id)->count());
    }

    #[Test]
    public function pending_manufacturer_pivot_upgrades_when_fda_labeler_exists(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR BF PEND');
        $listing = $this->listing($org, 'SSOR-BF-PEND', '88886-403', 'SSOR BF Pending');
        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => '88886-403-01',
            'gtin' => Gtin::fromPackageNdc('8888640301'),
            'ndc11' => '88886040301',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        $this->initializeDemo2Tenant();

        $wholesaler = $this->tenantPartner($org, PartnerType::Wholesaler, 'SSOR BF Pend Recv', stampFda: false);
        $first = app(AuthorizeFdaPackagingForPartner::class)->handle($wholesaler, $packaging);
        $this->tenantProductIds[] = (int) $first['product_id'];

        $this->assertSame(1, $first['manufacturer_pending']);
        $this->assertSame(
            AuthorizationStatus::PendingManufacturer->value,
            $wholesaler->products()->where('products.id', $first['product_id'])->first()?->pivot->authorization_status,
        );

        $this->tenantPartner($org, PartnerType::Manufacturer, 'SSOR BF Pend Mfr');

        $second = app(AuthorizeFdaPackagingForPartner::class)->handle($wholesaler, $packaging);

        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['manufacturer_pending']);
        $this->assertSame(
            AuthorizationStatus::Authorized->value,
            $wholesaler->fresh()->products()->where('products.id', $first['product_id'])->first()?->pivot->authorization_status,
        );
    }

    #[Test]
    public function manufacturer_resolves_through_fda_stamp_when_labeler_partner_exists(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR BF DUAL');
        $listing = $this->listing($org, 'SSOR-BF-DUAL', '88886-404', 'SSOR BF Dual');
        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => '88886-404-01',
            'gtin' => Gtin::fromPackageNdc('8888640401'),
            'ndc11' => '88886040401',
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        $this->initializeDemo2Tenant();

        $manufacturer = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => 'SSOR BF Dual Mfr',
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Manufacturer,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = $manufacturer->id;

        $wholesaler = $this->tenantPartner($org, PartnerType::Wholesaler, 'SSOR BF Dual Recv', stampFda: false);
        $result = app(AuthorizeFdaPackagingForPartner::class)->handle($wholesaler, $packaging);
        $this->tenantProductIds[] = (int) $result['product_id'];

        $this->assertSame(0, $result['manufacturer_pending']);
        $product = Product::query()->findOrFail($result['product_id']);
        $this->assertSame($manufacturer->id, $product->trading_partner_id);
    }

    #[Test]
    public function authorized_major_ids_use_fda_orgs_when_catalog_slugs_are_missing(): void
    {
        $definition = MajorWholesalers::definitions()[0];
        $org = FdaOrganization::query()->where('gln', $definition['gln'])->first();

        if ($org === null) {
            $org = FdaOrganization::query()->create([
                'original_name' => 'SSOR BF '.$definition['name'],
                'canonical_name' => 'SSOR BF '.strtoupper($definition['name']),
                'name' => 'SSOR BF '.$definition['name'],
                'gln' => $definition['gln'],
                'partner_type' => PartnerType::Wholesaler,
                'is_active' => true,
            ]);
            $this->orgIds[] = $org->id;
        }

        $this->initializeDemo2Tenant();

        $partner = $this->tenantPartner($org, PartnerType::Wholesaler, 'SSOR BF Major');

        $this->assertContains($org->id, MajorWholesalers::authorizedMajorCatalogIds());
        $this->assertTrue(MajorWholesalers::hasAnyAuthorizedMajor());
        $this->assertSame($partner->fda_organization_id, $org->id);
    }

    #[Test]
    public function fda_linked_partner_defaults_to_fda_site_create_mode(): void
    {
        $org = $this->organization(PartnerType::Manufacturer, 'SSOR BF MODE');
        $this->initializeDemo2Tenant();

        $partner = $this->tenantPartner($org, PartnerType::Manufacturer, 'SSOR BF Mode Mfr');

        $this->assertSame(PartnerSiteCreate::MODE_FDA, PartnerSiteCreate::defaultCreateMode($partner));
        $this->assertTrue(PartnerSiteCreate::hasFdaLink($partner));
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

    private function tenantPartner(
        FdaOrganization $org,
        PartnerType $type,
        ?string $name = null,
        bool $stampFda = true,
    ): TradingPartner {
        $partner = TradingPartner::query()->create([
            'fda_organization_id' => $stampFda ? $org->id : null,
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
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }
    }
}
