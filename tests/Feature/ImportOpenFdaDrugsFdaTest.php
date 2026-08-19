<?php

namespace Tests\Feature;

use App\Actions\OpenFda\ImportOpenFdaDrugsFdaPackages;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Support\Gs1\Gtin;
use App\Support\OpenFda\OpenFdaDrugsFdaDataset;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportOpenFdaDrugsFdaTest extends TestCase
{
    #[Test]
    public function fixture_import_creates_packaging_for_existing_fda_product(): void
    {
        $this->cleanupFixtureRows();

        $fda = FdaProduct::query()->create([
            'product_id' => 'TEST-DRUGSFDA-1_aaa',
            'product_ndc' => '0116-4005',
            'brand_name' => 'Testulose',
            'generic_name' => 'Testulose',
            'dosage_form' => 'SOLUTION',
            'finished' => true,
        ]);

        $results = app(OpenFdaDrugsFdaDataset::class)->loadResults(
            base_path('tests/fixtures/openfda/drug-drugsfda-sample.json')
        );

        $counts = app(ImportOpenFdaDrugsFdaPackages::class)->handle($results);

        // 4 packages for the matched application + 2 for the unmatched one.
        $this->assertSame(4, $counts['packaging_upserted']);
        $this->assertSame(0, $counts['packaging_skipped_empty']);
        $this->assertSame(1, $counts['products_matched']);
        $this->assertSame(2, $counts['skipped_no_fda_product']);
        $this->assertSame(0, $counts['errors']);

        $packaging40 = FdaProductPackaging::query()->where('package_ndc', '0116-4005-40')->first();
        $packaging41 = FdaProductPackaging::query()->where('package_ndc', '0116-4005-41')->first();

        $this->assertNotNull($packaging40);
        $this->assertNotNull($packaging41);
        $this->assertSame($fda->id, $packaging40->fda_product_id);
        $this->assertSame($fda->id, $packaging41->fda_product_id);

        $gtin40 = Gtin::fromPackageNdc('0116-4005-40');
        $gtin41 = Gtin::fromPackageNdc('0116-4005-41');

        $this->assertNotNull($gtin40);
        $this->assertNotNull($gtin41);

        $this->assertSame($gtin40, $packaging40->gtin);
        $this->assertSame($gtin41, $packaging41->gtin);

        // No FdaProduct exists for 99999-303 — packaging must not be invented.
        $this->assertNull(FdaProductPackaging::query()->where('package_ndc', '99999-303-01')->first());
        $this->assertNull(FdaProductPackaging::query()->where('package_ndc', '99999-303-02')->first());

        // Idempotent re-run.
        $again = app(ImportOpenFdaDrugsFdaPackages::class)->handle($results);
        $this->assertSame(4, $again['packaging_upserted']);
        $this->assertSame(
            FdaProductPackaging::query()->count(),
            FdaProductPackaging::query()->whereIn('package_ndc', [
                '0116-4005-08', '0116-4005-40', '0116-4005-41', '0116-4005-16',
            ])->count()
        );

        $this->cleanupFixtureRows();
    }

    #[Test]
    public function existing_packaging_description_is_preserved(): void
    {
        $this->cleanupFixtureRows();

        $fda = FdaProduct::query()->create([
            'product_id' => 'TEST-DRUGSFDA-1_aaa',
            'product_ndc' => '0116-4005',
            'brand_name' => 'Testulose',
            'finished' => true,
        ]);

        FdaProductPackaging::query()->create([
            'package_ndc' => '0116-4005-40',
            'fda_product_id' => $fda->id,
            'description' => 'Pre-existing NDC description',
        ]);

        $results = app(OpenFdaDrugsFdaDataset::class)->loadResults(
            base_path('tests/fixtures/openfda/drug-drugsfda-sample.json')
        );

        app(ImportOpenFdaDrugsFdaPackages::class)->handle($results);

        $packaging40 = FdaProductPackaging::query()->where('package_ndc', '0116-4005-40')->first();
        $this->assertSame('Pre-existing NDC description', $packaging40->description);

        $this->cleanupFixtureRows();
    }

    private function cleanupFixtureRows(): void
    {
        $fdaIds = FdaProduct::query()
            ->whereIn('product_id', ['TEST-DRUGSFDA-1_aaa'])
            ->pluck('id');

        $packageNdcs = ['0116-4005-08', '0116-4005-40', '0116-4005-41', '0116-4005-16', '99999-303-01', '99999-303-02'];

        DB::table('fda_product_packaging')->whereIn('package_ndc', $packageNdcs)->delete();

        if ($fdaIds->isNotEmpty()) {
            DB::table('fda_product_active_ingredients')->whereIn('product_id_fk', $fdaIds)->delete();
            DB::table('fda_product_packaging')->whereIn('fda_product_id', $fdaIds)->delete();
            DB::table('fda_product_pharm_classes')->whereIn('product_id_fk', $fdaIds)->delete();
            DB::table('fda_product_routes')->whereIn('product_id_fk', $fdaIds)->delete();
            FdaProduct::query()->whereIn('id', $fdaIds)->delete();
        }
    }
}
