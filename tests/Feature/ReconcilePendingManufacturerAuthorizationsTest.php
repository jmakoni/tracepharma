<?php

namespace Tests\Feature;

use App\Actions\MasterData\AuthorizeFdaPackagingForPartner;
use App\Actions\MasterData\EnsureManufacturerPartnerFromCatalog;
use App\Actions\MasterData\ReconcilePendingManufacturerAuthorizations;
use App\Enums\AuthorizationStatus;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReconcilePendingManufacturerAuthorizationsTest extends TestCase
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
    private array $tenantProductIds = [];

    protected function tearDown(): void
    {
        $this->cleanupIntegrationFixtures();
        parent::tearDown();
    }

    #[Test]
    public function ensure_manufacturer_partner_upgrades_pending_pivots(): void
    {
        $org = $this->createLabelerOrganization('SSOR CUT RECON ENSURE');
        $packaging = $this->createRxPackaging($org, 'SSOR-CUT-RECON-ENSURE');

        $this->initializeDemo2Tenant();

        $wholesaler = TradingPartner::query()->create([
            'name' => 'Wholesaler Reconcile '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds = [$wholesaler->id];

        $authorizeResult = app(AuthorizeFdaPackagingForPartner::class)->handle(
            $wholesaler,
            $packaging,
            autoAddManufacturer: false,
        );
        $this->tenantProductIds = [(int) $authorizeResult['product_id']];

        $product = Product::query()->findOrFail($authorizeResult['product_id']);
        $this->assertNull($product->trading_partner_id);

        $pivot = $wholesaler->products()->where('products.id', $product->id)->first()?->pivot;
        $this->assertSame(AuthorizationStatus::PendingManufacturer->value, $pivot?->authorization_status);

        $manufacturer = app(EnsureManufacturerPartnerFromCatalog::class)->handle($org);
        $this->tenantPartnerIds[] = $manufacturer->id;

        $product->refresh();
        $this->assertSame($manufacturer->id, $product->trading_partner_id);

        $pivot = $wholesaler->products()->where('products.id', $product->id)->first()?->pivot;
        $this->assertSame(AuthorizationStatus::Authorized->value, $pivot?->authorization_status);
        $this->assertNotNull($pivot?->authorized_at);
    }

    #[Test]
    public function reconcile_action_links_products_and_authorizes_pending_pivots(): void
    {
        $org = $this->createLabelerOrganization('SSOR CUT RECON DIRECT');
        [$listing, $packaging] = $this->createRxListingAndPackaging($org, 'SSOR-CUT-RECON-DIRECT');

        $this->initializeDemo2Tenant();

        $manufacturer = TradingPartner::query()->create([
            'fda_organization_id' => $org->id,
            'name' => 'Existing Mfg '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Manufacturer,
            'country_code' => 'US',
            'is_active' => true,
        ]);

        $wholesaler = TradingPartner::query()->create([
            'name' => 'Wholesaler Direct Reconcile '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds = [$manufacturer->id, $wholesaler->id];

        $product = Product::query()->create([
            'fda_product_id' => $listing->id,
            'fda_product_packaging_id' => $packaging->id,
            'name' => $listing->name,
            'gtin' => $packaging->gtin,
            'ndc11' => $packaging->ndc11,
            'is_active' => true,
        ]);
        $this->tenantProductIds = [$product->id];

        $wholesaler->products()->attach($product->id, [
            'authorization_status' => AuthorizationStatus::PendingManufacturer->value,
            'authorized_at' => now(),
        ]);

        $result = app(ReconcilePendingManufacturerAuthorizations::class)->handle($manufacturer);

        $this->assertSame(1, $result['products_linked']);
        $this->assertSame(1, $result['pivots_authorized']);

        $product->refresh();
        $this->assertSame($manufacturer->id, $product->trading_partner_id);

        $pivot = $wholesaler->products()->where('products.id', $product->id)->first()?->pivot;
        $this->assertSame(AuthorizationStatus::Authorized->value, $pivot?->authorization_status);
    }

    private function createLabelerOrganization(string $name): FdaOrganization
    {
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => strtoupper($name),
            'name' => $name,
            'partner_type' => PartnerType::Manufacturer,
            'gln' => fake()->unique()->numerify('#############'),
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        return $org;
    }

    private function createRxPackaging(FdaOrganization $org, string $productId): FdaProductPackaging
    {
        [, $packaging] = $this->createRxListingAndPackaging($org, $productId);

        return $packaging;
    }

    /**
     * @return array{0: FdaProduct, 1: FdaProductPackaging}
     */
    private function createRxListingAndPackaging(FdaOrganization $org, string $productId): array
    {
        $packageNdc = sprintf('88886-%03d-01', random_int(100, 999));

        $listing = FdaProduct::query()->create([
            'product_id' => $productId.'-'.uniqid(),
            'product_ndc' => substr($packageNdc, 0, 9),
            'brand_name' => 'Rx '.$productId,
            'name' => 'Rx '.$productId,
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->productIds[] = $listing->id;

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => $packageNdc,
            'gtin' => Gtin::fromPackageNdc(str_replace('-', '', $packageNdc)),
            'ndc11' => preg_replace('/\D+/', '', $packageNdc),
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

    private function cleanupIntegrationFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->tenantPartnerIds !== []) {
                foreach ($this->tenantPartnerIds as $partnerId) {
                    TradingPartner::query()->find($partnerId)?->products()->detach();
                }
            }

            if ($this->tenantProductIds !== []) {
                Product::query()->whereIn('id', $this->tenantProductIds)->delete();
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

        $this->orgIds = [];
        $this->productIds = [];
        $this->packagingIds = [];
        $this->tenantPartnerIds = [];
        $this->tenantProductIds = [];
    }
}
