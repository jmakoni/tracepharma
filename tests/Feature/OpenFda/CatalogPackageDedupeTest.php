<?php

namespace Tests\Feature\OpenFda;

use App\Actions\OpenFda\ImportOpenFdaDrugsFdaPackages;
use App\Actions\OpenFda\ImportOpenFdaNdcProducts;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * openFDA hands the same package to us from two datasets. Both must land on one
 * fda_product_packaging row, and Drugs@FDA must never file a package under a
 * product it does not belong to.
 */
class CatalogPackageDedupeTest extends TestCase
{
    private const PRODUCT_NDC = '99991-101';

    private const OTHER_PRODUCT_NDC = '99991-202';

    private const PACKAGE_NDC = '99991-101-01';

    private const OTHER_PACKAGE_NDC = '99991-202-01';

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
    public function a_upc_keyed_package_is_not_forked_into_a_second_row_by_the_drugsfda_import(): void
    {
        $upc = $this->upcGtinUnrelatedToPackageNdc();

        $ndcCounts = app(ImportOpenFdaNdcProducts::class)->handle([$this->ndcDirectoryResult($upc)]);

        $this->assertSame(1, $ndcCounts['packaging_upserted']);

        $packaging = FdaProductPackaging::query()->where('package_ndc', self::PACKAGE_NDC)->firstOrFail();
        $this->assertSame($upc, $packaging->gtin);
        $this->assertSame(Ndc::toNdc11(self::PACKAGE_NDC), $packaging->ndc11);

        $drugsCounts = app(ImportOpenFdaDrugsFdaPackages::class)->handle([$this->drugsFdaResult([self::PACKAGE_NDC])]);

        $this->assertSame(1, $drugsCounts['packaging_upserted']);
        $this->assertSame(0, $drugsCounts['errors']);

        $this->assertSame(1, FdaProductPackaging::query()->where('package_ndc', self::PACKAGE_NDC)->count());
        $this->assertNull(FdaProductPackaging::query()->where('gtin', Gtin::fromPackageNdc(self::PACKAGE_NDC))->first());

        $packaging->refresh();
        $this->assertSame($upc, $packaging->gtin);
        $this->assertSame(Ndc::toNdc11(self::PACKAGE_NDC), $packaging->ndc11);
    }

    #[Test]
    public function the_ndc_import_writes_ndc11_on_every_packaging_row(): void
    {
        app(ImportOpenFdaNdcProducts::class)->handle([$this->ndcDirectoryResult(null)]);

        $packaging = FdaProductPackaging::query()->where('package_ndc', self::PACKAGE_NDC)->firstOrFail();

        $this->assertSame(Gtin::fromPackageNdc(self::PACKAGE_NDC), $packaging->gtin);
        $this->assertSame(Ndc::toNdc11(self::PACKAGE_NDC), $packaging->ndc11);
    }

    #[Test]
    public function drugsfda_does_not_attach_a_package_owned_by_an_unmatched_product_ndc(): void
    {
        app(ImportOpenFdaNdcProducts::class)->handle([$this->ndcDirectoryResult(null)]);

        $fdaProduct = FdaProduct::query()->where('product_ndc', self::PRODUCT_NDC)->firstOrFail();

        $counts = app(ImportOpenFdaDrugsFdaPackages::class)->handle([
            $this->drugsFdaResult(
                [self::PACKAGE_NDC, self::OTHER_PACKAGE_NDC],
                [self::PRODUCT_NDC, self::OTHER_PRODUCT_NDC],
            ),
        ]);

        $this->assertSame(1, $counts['packaging_upserted']);
        $this->assertSame(1, $counts['skipped_no_fda_product']);
        $this->assertSame(0, $counts['errors']);

        $this->assertSame(
            $fdaProduct->id,
            FdaProductPackaging::query()->where('package_ndc', self::PACKAGE_NDC)->value('fda_product_id'),
        );

        $this->assertNull(FdaProductPackaging::query()->where('package_ndc', self::OTHER_PACKAGE_NDC)->first());
    }

    /**
     * @return array<string, mixed>
     */
    private function ndcDirectoryResult(?string $upc): array
    {
        return [
            'product_id' => 'TEST-DEDUPE-1_aaa',
            'product_ndc' => self::PRODUCT_NDC,
            'generic_name' => 'Dedupe Generic',
            'brand_name' => 'Dedupe Brand',
            'labeler_name' => 'OpenFDA Dedupe Labeler',
            'finished' => true,
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'dosage_form' => 'TABLET',
            'active_ingredients' => [['name' => 'DEDUPINE', 'strength' => '10 mg/1']],
            'packaging' => [
                ['package_ndc' => self::PACKAGE_NDC, 'description' => '30 TABLET in 1 BOTTLE'],
            ],
            'openfda' => $upc === null ? [] : ['upc' => [$upc]],
        ];
    }

    /**
     * @param  list<string>  $packageNdcs
     * @param  list<string>  $productNdcs
     * @return array<string, mixed>
     */
    private function drugsFdaResult(array $packageNdcs, array $productNdcs = [self::PRODUCT_NDC]): array
    {
        return [
            'application_number' => 'ANDA999991',
            'openfda' => [
                'brand_name' => ['Dedupe Brand'],
                'generic_name' => ['Dedupe Generic'],
                'product_ndc' => $productNdcs,
                'package_ndc' => $packageNdcs,
            ],
        ];
    }

    private function upcGtinUnrelatedToPackageNdc(): string
    {
        $body = '0777'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $gtin = $body.Gtin::checkDigit($body);

        $this->assertNotSame(Gtin::fromPackageNdc(self::PACKAGE_NDC), $gtin);
        $this->assertNull(Gtin::ndc10FromNdcEncodedGtin($gtin));

        return $gtin;
    }

    private function cleanupFixtureRows(): void
    {
        $packageNdcs = [self::PACKAGE_NDC, self::OTHER_PACKAGE_NDC];

        DB::table('fda_product_packaging')->whereIn('package_ndc', $packageNdcs)->delete();

        $fdaIds = FdaProduct::query()
            ->whereIn('product_ndc', [self::PRODUCT_NDC, self::OTHER_PRODUCT_NDC])
            ->pluck('id');

        if ($fdaIds->isNotEmpty()) {
            DB::table('fda_product_active_ingredients')->whereIn('product_id_fk', $fdaIds)->delete();
            DB::table('fda_product_packaging')->whereIn('fda_product_id', $fdaIds)->delete();
            DB::table('fda_product_pharm_classes')->whereIn('product_id_fk', $fdaIds)->delete();
            DB::table('fda_product_routes')->whereIn('product_id_fk', $fdaIds)->delete();
            FdaProduct::query()->whereIn('id', $fdaIds)->delete();
        }
    }
}
