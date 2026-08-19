<?php

namespace Tests\Feature\OpenFda;

use App\Actions\OpenFda\ImportOpenFdaDrugsFdaPackages;
use App\Actions\OpenFda\ImportOpenFdaNdcProducts;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductActiveIngredient;
use App\Models\Fda\FdaProductPackaging;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A combination product is not described by its first active ingredient. Both
 * openFDA importers must write the whole strength onto the FDA listing, or the
 * receiving screen shows "5 mg/1" for a 5 mg / 160 mg tablet and two different
 * combinations become indistinguishable.
 */
class CatalogStrengthConcatTest extends TestCase
{
    private const NDC_PRODUCT_NDC = '99992-101';

    private const NDC_PACKAGE_NDC = '99992-101-01';

    private const DRUGSFDA_PRODUCT_NDC = '99992-202';

    private const DRUGSFDA_PACKAGE_NDC = '99992-202-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupFixtureRows();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtureRows();

        parent::tearDown();
    }

    #[Test]
    public function the_ndc_directory_import_writes_every_ingredient_strength(): void
    {
        app(ImportOpenFdaNdcProducts::class)->handle([[
            'product_id' => 'TEST-STRENGTH-1_aaa',
            'product_ndc' => self::NDC_PRODUCT_NDC,
            'generic_name' => 'Amlodipine and Valsartan',
            'brand_name' => 'Strengthex',
            'labeler_name' => 'OpenFDA Strength Labeler',
            'finished' => true,
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'dosage_form' => 'TABLET, FILM COATED',
            'active_ingredients' => [
                ['name' => 'AMLODIPINE BESYLATE', 'strength' => '5 mg/1'],
                ['name' => 'VALSARTAN', 'strength' => '160 mg/1'],
            ],
            'packaging' => [
                ['package_ndc' => self::NDC_PACKAGE_NDC, 'description' => '30 TABLET in 1 BOTTLE'],
            ],
            'openfda' => [],
        ]]);

        $listing = FdaProduct::query()->where('product_ndc', self::NDC_PRODUCT_NDC)->firstOrFail();
        $packaging = FdaProductPackaging::query()->where('package_ndc', self::NDC_PACKAGE_NDC)->firstOrFail();

        $this->assertSame('5 mg/1; 160 mg/1', $listing->activeIngredientStrength());
        $this->assertSame($listing->id, $packaging->fda_product_id);
    }

    #[Test]
    public function the_drugsfda_import_writes_packaging_without_a_catalog_row(): void
    {
        $fdaProduct = FdaProduct::query()->create([
            'product_id' => 'TEST-STRENGTH-2_bbb',
            'product_ndc' => self::DRUGSFDA_PRODUCT_NDC,
            'brand_name' => 'Strengthex Duo',
            'generic_name' => 'Sulfamethoxazole and Trimethoprim',
            'dosage_form' => 'TABLET',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'finished' => true,
        ]);

        FdaProductActiveIngredient::query()->insert([
            ['product_id_fk' => $fdaProduct->id, 'name' => 'SULFAMETHOXAZOLE', 'strength' => '800 mg/1'],
            ['product_id_fk' => $fdaProduct->id, 'name' => 'TRIMETHOPRIM', 'strength' => '160 mg/1'],
        ]);

        $counts = app(ImportOpenFdaDrugsFdaPackages::class)->handle([[
            'application_number' => 'ANDA099992',
            'openfda' => [
                'brand_name' => ['Strengthex Duo'],
                'generic_name' => ['Sulfamethoxazole and Trimethoprim'],
                'product_ndc' => [self::DRUGSFDA_PRODUCT_NDC],
                'package_ndc' => [self::DRUGSFDA_PACKAGE_NDC],
            ],
        ]]);

        $this->assertSame(1, $counts['packaging_upserted']);
        $this->assertSame(0, $counts['errors']);

        $packaging = FdaProductPackaging::query()->where('package_ndc', self::DRUGSFDA_PACKAGE_NDC)->firstOrFail();
        $this->assertSame($fdaProduct->id, $packaging->fda_product_id);
        $this->assertSame('800 mg/1; 160 mg/1', $fdaProduct->fresh()->activeIngredientStrength());
    }

    private function cleanupFixtureRows(): void
    {
        $packageNdcs = [self::NDC_PACKAGE_NDC, self::DRUGSFDA_PACKAGE_NDC];

        DB::table('fda_product_packaging')->whereIn('package_ndc', $packageNdcs)->delete();

        $fdaIds = FdaProduct::query()
            ->whereIn('product_ndc', [self::NDC_PRODUCT_NDC, self::DRUGSFDA_PRODUCT_NDC])
            ->pluck('id');

        if ($fdaIds->isEmpty()) {
            return;
        }

        DB::table('fda_product_active_ingredients')->whereIn('product_id_fk', $fdaIds)->delete();
        DB::table('fda_product_packaging')->whereIn('fda_product_id', $fdaIds)->delete();
        DB::table('fda_product_pharm_classes')->whereIn('product_id_fk', $fdaIds)->delete();
        DB::table('fda_product_routes')->whereIn('product_id_fk', $fdaIds)->delete();
        FdaProduct::query()->whereIn('id', $fdaIds)->delete();
    }
}
