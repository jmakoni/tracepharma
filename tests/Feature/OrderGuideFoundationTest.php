<?php

namespace Tests\Feature;

use App\Actions\MasterData\AuthorizeFdaPackagingForPartner;
use App\Actions\MasterData\SetPrimaryReceiveFromPartner;
use App\Actions\MasterData\UpdatePartnerProductAssortment;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderGuideFoundationTest extends TestCase
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
    public function is_primary_is_unique_per_product_across_partners(): void
    {
        $packaging = $this->createRxPackaging('SSOR-CUT2-PRIMARY-UNIQUE');

        $this->initializeDemo2Tenant();

        $partnerA = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 Partner A '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);

        $partnerB = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 Partner B '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds = [$partnerA->id, $partnerB->id];

        $authorizer = app(AuthorizeFdaPackagingForPartner::class);

        $first = $authorizer->handle($partnerA, $packaging);
        $productId = $first['product_id'];
        $this->assertNotNull($productId);
        $this->tenantProductIds = [$productId];

        $authorizer->handle($partnerB, $packaging);

        $pivotA = $partnerA->products()->where('products.id', $productId)->first()?->pivot;
        $pivotB = $partnerB->products()->where('products.id', $productId)->first()?->pivot;

        $this->assertTrue((bool) $pivotA?->is_primary);
        $this->assertFalse((bool) $pivotB?->is_primary);

        app(SetPrimaryReceiveFromPartner::class)->handle($productId, $partnerB->id);

        $pivotA = $partnerA->products()->where('products.id', $productId)->first()?->pivot;
        $pivotB = $partnerB->products()->where('products.id', $productId)->first()?->pivot;

        $this->assertFalse((bool) $pivotA?->is_primary);
        $this->assertTrue((bool) $pivotB?->is_primary);

        $this->assertSame(
            1,
            $partnerA->products()->where('products.id', $productId)->wherePivot('is_primary', true)->count()
                + $partnerB->products()->where('products.id', $productId)->wherePivot('is_primary', true)->count(),
        );
    }

    #[Test]
    public function first_authorize_sets_is_primary_when_none_exists(): void
    {
        $packaging = $this->createRxPackaging('SSOR-CUT2-PRIMARY-DEFAULT');

        $this->initializeDemo2Tenant();

        $partner = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 First Partner '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds = [$partner->id];

        $result = app(AuthorizeFdaPackagingForPartner::class)->handle($partner, $packaging);
        $this->tenantProductIds = [(int) $result['product_id']];

        $pivot = $partner->products()->where('products.id', $result['product_id'])->first()?->pivot;
        $this->assertTrue((bool) $pivot?->is_primary);
    }

    #[Test]
    public function update_partner_product_assortment_updates_pivot_fields(): void
    {
        $packaging = $this->createRxPackaging('SSOR-CUT2-PIVOT-UPDATE');

        $this->initializeDemo2Tenant();

        $partner = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 Pivot Partner '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds = [$partner->id];

        $result = app(AuthorizeFdaPackagingForPartner::class)->handle($partner, $packaging);
        $product = Product::query()->findOrFail($result['product_id']);
        $this->tenantProductIds = [$product->id];

        app(UpdatePartnerProductAssortment::class)->handle($partner, $product, [
            'partner_item_number' => 'WH-12345',
            'uom_code' => 'EA',
            'units_per_case' => 12,
            'is_primary' => true,
        ]);

        $pivot = $partner->products()->where('products.id', $product->id)->first()?->pivot;
        $this->assertSame('WH-12345', $pivot?->partner_item_number);
        $this->assertSame('EA', $pivot?->uom_code);
        $this->assertSame(12, (int) $pivot?->units_per_case);
        $this->assertTrue((bool) $pivot?->is_primary);
    }

    #[Test]
    public function partner_item_number_is_rejected_when_it_already_names_another_product(): void
    {
        $packagingA = $this->createRxPackaging('SSOR-CUT2-SKU-CONFLICT-A');
        $packagingB = $this->createRxPackaging('SSOR-CUT2-SKU-CONFLICT-B');

        $this->initializeDemo2Tenant();

        $partner = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 SKU Partner '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds = [$partner->id];

        $authorizer = app(AuthorizeFdaPackagingForPartner::class);
        $productA = Product::query()->findOrFail($authorizer->handle($partner, $packagingA)['product_id']);
        $productB = Product::query()->findOrFail($authorizer->handle($partner, $packagingB)['product_id']);
        $this->tenantProductIds = [$productA->id, $productB->id];

        $assortment = app(UpdatePartnerProductAssortment::class);

        $assortment->handle($partner, $productA, ['partner_item_number' => 'DUP-1']);

        $this->assertSame(
            $productA->id,
            UpdatePartnerProductAssortment::conflictingProductId($partner, 'DUP-1', $productB->id),
        );

        try {
            $assortment->handle($partner, $productB, ['partner_item_number' => 'DUP-1']);
            $this->fail('Expected a DomainException for a duplicate partner item number.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('DUP-1', $e->getMessage());
        }

        // The blocked update must not have written a partial pivot.
        $pivotB = $partner->products()->where('products.id', $productB->id)->first()?->pivot;
        $this->assertNull($pivotB?->partner_item_number);

        // Re-saving a product under the number it already holds is not a conflict.
        $assortment->handle($partner, $productA, ['partner_item_number' => 'DUP-1', 'uom_code' => 'CS']);
        $pivotA = $partner->products()->where('products.id', $productA->id)->first()?->pivot;
        $this->assertSame('DUP-1', $pivotA?->partner_item_number);
        $this->assertSame('CS', $pivotA?->uom_code);
    }

    #[Test]
    public function blank_partner_item_numbers_are_exempt_from_uniqueness_and_scoped_per_partner(): void
    {
        $packagingA = $this->createRxPackaging('SSOR-CUT2-SKU-SCOPE-A');
        $packagingB = $this->createRxPackaging('SSOR-CUT2-SKU-SCOPE-B');

        $this->initializeDemo2Tenant();

        $partnerOne = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 Scope Partner One '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);

        $partnerTwo = TradingPartner::query()->create([
            'name' => 'SSOR CUT2 Scope Partner Two '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds = [$partnerOne->id, $partnerTwo->id];

        $authorizer = app(AuthorizeFdaPackagingForPartner::class);
        $productA = Product::query()->findOrFail($authorizer->handle($partnerOne, $packagingA)['product_id']);
        $productB = Product::query()->findOrFail($authorizer->handle($partnerOne, $packagingB)['product_id']);
        $authorizer->handle($partnerTwo, $packagingA);
        $this->tenantProductIds = [$productA->id, $productB->id];

        $assortment = app(UpdatePartnerProductAssortment::class);

        // Most of an assortment carries no partner SKU, so blank must never collide.
        $assortment->handle($partnerOne, $productA, ['partner_item_number' => '']);
        $assortment->handle($partnerOne, $productB, ['partner_item_number' => '   ']);

        $this->assertNull(
            $partnerOne->products()->where('products.id', $productA->id)->first()?->pivot?->partner_item_number,
        );
        $this->assertNull(
            UpdatePartnerProductAssortment::conflictingProductId($partnerOne, null, $productB->id),
        );
        $this->assertNull(
            UpdatePartnerProductAssortment::conflictingProductId($partnerOne, '', $productB->id),
        );

        // The number is the partner's own SKU, so two partners may use the same string.
        $assortment->handle($partnerOne, $productA, ['partner_item_number' => 'SHARED-1']);
        $assortment->handle($partnerTwo, $productA, ['partner_item_number' => 'SHARED-1']);

        $this->assertNull(
            UpdatePartnerProductAssortment::conflictingProductId($partnerTwo, 'SHARED-1', $productA->id),
        );
        $this->assertSame(
            'SHARED-1',
            $partnerTwo->products()->where('products.id', $productA->id)->first()?->pivot?->partner_item_number,
        );
    }

    private function createRxPackaging(string $productIdPrefix): FdaProductPackaging
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT2 '.$productIdPrefix,
            'canonical_name' => strtoupper('SSOR CUT2 '.$productIdPrefix),
            'name' => 'SSOR CUT2 '.$productIdPrefix,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        $packageNdc = sprintf('88887-%03d-01', random_int(100, 999));

        $listing = FdaProduct::query()->create([
            'product_id' => $productIdPrefix.'-'.uniqid(),
            'product_ndc' => substr($packageNdc, 0, 9),
            'brand_name' => 'Rx '.$productIdPrefix,
            'name' => 'Rx '.$productIdPrefix,
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->productIds[] = $listing->id;

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => $packageNdc,
            'gtin' => Gtin::fromPackageNdc(str_replace('-', '', $packageNdc)) ?? fake()->unique()->numerify('##############'),
            'ndc11' => preg_replace('/\D+/', '', $packageNdc),
            'is_active' => true,
        ]);
        $this->packagingIds[] = $packaging->id;

        return $packaging;
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
