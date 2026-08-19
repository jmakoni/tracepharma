<?php

namespace Tests\Feature\MasterData;

use App\Actions\MasterData\AuthorizeFdaPackagingForPartner;
use App\Enums\PackLevel;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\ProductPackagingLink;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A case and the units inside it share an NDC-11 but are different trade items.
 * Authorizing a scanned case GTIN must produce a product carrying that GTIN, not
 * silently resolve to the unit and leave the case unknown to product master.
 */
class AuthorizeCaseGtinTest extends TestCase
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
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function a_case_gtin_gets_its_own_product_while_the_unit_keeps_the_ndc11(): void
    {
        $pair = $this->uniquePackagingGtinPair();
        $packaging = $this->createRxPackaging($pair['package_ndc'], $pair['unit_gtin'], $pair['ndc11']);

        $this->initializeDemo2Tenant();

        $wholesaler = $this->makeWholesaler('SSOR CUT2 Case GTIN');
        $authorizer = app(AuthorizeFdaPackagingForPartner::class);

        $unit = $authorizer->handle($wholesaler, $packaging);
        $this->assertSame(1, $unit['added']);
        $this->tenantProductIds[] = (int) $unit['product_id'];

        $unitProduct = Product::query()->findOrFail($unit['product_id']);
        $this->assertSame($pair['unit_gtin'], $unitProduct->gtin);
        $this->assertSame($pair['ndc11'], $unitProduct->ndc11);

        $case = $authorizer->handle(
            $wholesaler,
            $packaging,
            gtinOverride: $pair['case_gtin'],
        );
        $this->assertSame(1, $case['added']);
        $this->assertNotSame($unit['product_id'], $case['product_id']);
        $this->tenantProductIds[] = (int) $case['product_id'];

        $caseProduct = Product::query()->findOrFail($case['product_id']);
        $this->assertSame($pair['case_gtin'], $caseProduct->gtin);

        // products.ndc11 is UNIQUE: the unit keeps it, the case is known by GTIN.
        $this->assertNull($caseProduct->ndc11);
        $this->assertSame($pair['ndc11'], $unitProduct->fresh()->ndc11);
        $this->assertSame($pair['package_ndc'], $caseProduct->package_ndc);

        $this->assertTrue($wholesaler->products()->where('products.id', $caseProduct->id)->exists());

        // Re-authorizing the case GTIN resolves to the case product, not the unit.
        $again = $authorizer->handle(
            $wholesaler,
            $packaging,
            gtinOverride: $pair['case_gtin'],
        );
        $this->assertSame($caseProduct->id, $again['product_id']);
        $this->assertSame(1, $again['skipped']);
    }

    #[Test]
    public function authorizing_a_case_over_a_known_unit_records_the_packaging_link(): void
    {
        $pair = $this->uniquePackagingGtinPair();
        $packaging = $this->createRxPackaging($pair['package_ndc'], $pair['unit_gtin'], $pair['ndc11']);

        $this->initializeDemo2Tenant();

        $wholesaler = $this->makeWholesaler('SSOR CUT2 Packaging Link');
        $authorizer = app(AuthorizeFdaPackagingForPartner::class);

        $unit = $authorizer->handle($wholesaler, $packaging);
        $this->tenantProductIds[] = (int) $unit['product_id'];

        $case = $authorizer->handle(
            $wholesaler,
            $packaging,
            ['units_per_case' => 12],
            gtinOverride: $pair['case_gtin'],
        );
        $this->tenantProductIds[] = (int) $case['product_id'];

        $link = ProductPackagingLink::query()
            ->where('parent_product_id', $case['product_id'])
            ->sole();

        $this->assertSame((int) $unit['product_id'], (int) $link->child_product_id);
        $this->assertSame(12, $link->quantity);
        $this->assertSame(PackLevel::Case, $link->pack_level);

        // Re-authorizing must not stack a second link on the same pair.
        $authorizer->handle(
            $wholesaler,
            $packaging,
            ['units_per_case' => 12],
            gtinOverride: $pair['case_gtin'],
        );
        $this->assertSame(
            1,
            ProductPackagingLink::query()->where('parent_product_id', $case['product_id'])->count(),
        );
    }

    /**
     * The case can be authorized first — a file often carries only the case line — and the
     * unit second. Containment is read off the GTIN indicator digits, so the case is the
     * parent either way; filing the unit as the parent would put the case's net content on
     * the wrong trade item in outbound master data.
     */
    #[Test]
    public function authorizing_the_unit_after_the_case_still_files_the_case_as_the_parent(): void
    {
        $pair = $this->uniquePackagingGtinPair();
        $packaging = $this->createRxPackaging($pair['package_ndc'], $pair['unit_gtin'], $pair['ndc11']);

        $this->initializeDemo2Tenant();

        $wholesaler = $this->makeWholesaler('SSOR CUT2 Case First');
        $authorizer = app(AuthorizeFdaPackagingForPartner::class);

        $case = $authorizer->handle(
            $wholesaler,
            $packaging,
            ['units_per_case' => 24],
            gtinOverride: $pair['case_gtin'],
        );
        $this->tenantProductIds[] = (int) $case['product_id'];

        // The case was first, so it holds the NDC-11 and the unit is the one created
        // without it.
        $this->assertSame($pair['ndc11'], Product::query()->findOrFail($case['product_id'])->ndc11);

        $unit = $authorizer->handle($wholesaler, $packaging, ['units_per_case' => 24]);
        $this->tenantProductIds[] = (int) $unit['product_id'];
        $this->assertNotSame($case['product_id'], $unit['product_id']);

        $link = ProductPackagingLink::query()
            ->where('child_product_id', $unit['product_id'])
            ->sole();

        $this->assertSame((int) $case['product_id'], (int) $link->parent_product_id);
        $this->assertSame(24, $link->quantity);
        $this->assertSame(PackLevel::Case, $link->pack_level);

        $this->assertSame(
            0,
            ProductPackagingLink::query()->where('parent_product_id', $unit['product_id'])->count(),
            'The unit must never be recorded as containing the case.',
        );
    }

    #[Test]
    public function a_case_without_a_known_units_per_case_records_no_packaging_link(): void
    {
        $pair = $this->uniquePackagingGtinPair();
        $packaging = $this->createRxPackaging($pair['package_ndc'], $pair['unit_gtin'], $pair['ndc11']);

        $this->initializeDemo2Tenant();

        $wholesaler = $this->makeWholesaler('SSOR CUT2 No Units Per Case');
        $authorizer = app(AuthorizeFdaPackagingForPartner::class);

        $unit = $authorizer->handle($wholesaler, $packaging);
        $this->tenantProductIds[] = (int) $unit['product_id'];

        $case = $authorizer->handle($wholesaler, $packaging, gtinOverride: $pair['case_gtin']);
        $this->tenantProductIds[] = (int) $case['product_id'];

        // Net content is read off the link quantity, so an unknown pack size
        // must leave no link rather than assert a made-up one.
        $this->assertSame(
            0,
            ProductPackagingLink::query()->where('parent_product_id', $case['product_id'])->count(),
        );
    }

    #[Test]
    public function an_existing_product_without_a_gtin_still_adopts_the_packaging_gtin(): void
    {
        $pair = $this->uniquePackagingGtinPair();
        $packaging = $this->createRxPackaging($pair['package_ndc'], $pair['unit_gtin'], $pair['ndc11']);

        $this->initializeDemo2Tenant();

        $stub = Product::query()->create([
            'gtin' => null,
            'name' => 'NDC-only stub '.uniqid(),
            'package_ndc' => $pair['package_ndc'],
            'ndc11' => $pair['ndc11'],
            'is_active' => true,
        ]);
        $this->tenantProductIds[] = (int) $stub->getKey();

        $wholesaler = $this->makeWholesaler('SSOR CUT2 Adopt GTIN');

        $result = app(AuthorizeFdaPackagingForPartner::class)->handle($wholesaler, $packaging);

        $this->assertSame($stub->id, $result['product_id']);
        $this->assertSame(0, $result['added']);
        $this->assertSame($pair['unit_gtin'], $stub->fresh()->gtin);
    }

    /**
     * Unit (indicator 0) and case (indicator 3) GTINs over one unused package NDC.
     *
     * @return array{package_ndc: string, unit_gtin: string, case_gtin: string, ndc11: string}
     */
    private function uniquePackagingGtinPair(): array
    {
        do {
            $packageNdc = sprintf(
                '%04d-%04d-%02d',
                random_int(1000, 9999),
                random_int(0, 9999),
                random_int(0, 99),
            );
            $ndc10 = preg_replace('/\D+/', '', $packageNdc) ?? '';
            $ndc11 = Ndc::toNdc11($packageNdc);
            $unitBody = '003'.$ndc10;
            $caseBody = '303'.$ndc10;
            $unitGtin = $unitBody.Gtin::checkDigit($unitBody);
            $caseGtin = $caseBody.Gtin::checkDigit($caseBody);
        } while (
            $ndc11 === null
            || strlen($ndc10) !== 10
            || FdaProductPackaging::query()
                ->where(fn ($query) => $query->where('package_ndc', $packageNdc)
                    ->orWhere('ndc11', $ndc11)
                    ->orWhereIn('gtin', [$unitGtin, $caseGtin]))
                ->exists()
        );

        return [
            'package_ndc' => $packageNdc,
            'unit_gtin' => $unitGtin,
            'case_gtin' => $caseGtin,
            'ndc11' => $ndc11,
        ];
    }

    private function createRxPackaging(string $packageNdc, string $gtin, string $ndc11): FdaProductPackaging
    {
        $suffix = uniqid();
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT2 Case GTIN Labeler '.$suffix,
            'canonical_name' => 'SSOR CUT2 CASE GTIN LABELER '.$suffix,
            'name' => 'SSOR CUT2 Case GTIN Labeler '.$suffix,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        $listing = FdaProduct::query()->create([
            'product_id' => 'SSOR-CUT2-CASE-'.uniqid(),
            'product_ndc' => substr($packageNdc, 0, 9),
            'brand_name' => 'SSOR CUT2 Case GTIN Rx',
            'name' => 'SSOR CUT2 Case GTIN Rx',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->productIds[] = (int) $listing->getKey();

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => $packageNdc,
            'gtin' => $gtin,
            'ndc11' => $ndc11,
            'is_active' => true,
        ]);
        $this->packagingIds[] = (int) $packaging->getKey();

        return $packaging;
    }

    private function makeWholesaler(string $label): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => $label.' '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->tenantPartnerIds[] = (int) $partner->getKey();

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
            foreach ($this->tenantPartnerIds as $partnerId) {
                TradingPartner::query()->find($partnerId)?->products()->detach();
            }

            if ($this->tenantProductIds !== []) {
                ProductPackagingLink::query()
                    ->whereIn('parent_product_id', $this->tenantProductIds)
                    ->orWhereIn('child_product_id', $this->tenantProductIds)
                    ->delete();
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
