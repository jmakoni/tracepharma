<?php

namespace Tests\Feature;

use App\Actions\OpenFda\ImportOpenFdaNdcPartners;
use App\Actions\OpenFda\ImportOpenFdaNdcProducts;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Support\Gs1\Gtin;
use App\Support\OpenFda\OpenFdaNdcDataset;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportOpenFdaNdcTest extends TestCase
{
    #[Test]
    public function fixture_import_creates_organizations_and_packaging_without_catalog_rows(): void
    {
        $this->cleanupFixtureRows();

        $results = app(OpenFdaNdcDataset::class)->loadResults(
            base_path('tests/fixtures/openfda/drug-ndc-sample.json')
        );

        $partnerCounts = app(ImportOpenFdaNdcPartners::class)->handle($results);
        $this->assertSame(1, $partnerCounts['orgs_created']);
        $this->assertSame(1, $partnerCounts['orgs_reviewed']);
        $this->assertSame(1, $partnerCounts['orgs_linked']);

        $productCounts = app(ImportOpenFdaNdcProducts::class)->handle($results);
        $this->assertSame(2, $productCounts['fda_upserted']);
        $this->assertSame(3, $productCounts['packaging_upserted']);
        $this->assertSame(1, $productCounts['org_linked']);
        $this->assertSame(1, $productCounts['missing_org']);

        $alpha = FdaOrganization::query()->where('canonical_name', 'OPENFDA TEST LABELER ALPHA')->first();
        $this->assertNotNull($alpha);
        $this->assertNull(FdaOrganization::query()->where('canonical_name', 'OPENFDA TEST LABELER BETA')->first());

        $fdaAlpha = FdaProduct::query()->where('product_id', 'TEST-OPENFDA-1_aaa')->first();
        $this->assertNotNull($fdaAlpha);
        $this->assertSame($alpha->id, $fdaAlpha->fda_organization_id);

        $gtin10101 = Gtin::fromPackageNdc('99999-101-01');
        $gtin20201 = Gtin::fromPackageNdc('99999-202-01');
        $this->assertNotNull($gtin10101);
        $this->assertNotNull($gtin20201);

        $packA = FdaProductPackaging::query()->where('package_ndc', '99999-101-01')->first();
        $packB = FdaProductPackaging::query()->where('package_ndc', '99999-202-01')->first();
        $this->assertNotNull($packA);
        $this->assertSame($fdaAlpha->id, $packA->fda_product_id);
        $this->assertSame($gtin10101, $packA->gtin);
        $this->assertNotNull($packB);
        $this->assertSame($gtin20201, $packB->gtin);

        $again = app(ImportOpenFdaNdcPartners::class)->handle($results);
        $this->assertSame(0, $again['orgs_created']);
        $this->assertSame(1, $again['orgs_reviewed']);
        $this->assertSame(1, $again['orgs_linked']);

        $this->cleanupFixtureRows();
    }

    private function cleanupFixtureRows(): void
    {
        $fdaIds = FdaProduct::query()
            ->whereIn('product_id', ['TEST-OPENFDA-1_aaa', 'TEST-OPENFDA-2_bbb'])
            ->pluck('id');

        if ($fdaIds->isNotEmpty()) {
            DB::table('fda_product_active_ingredients')->whereIn('product_id_fk', $fdaIds)->delete();
            DB::table('fda_product_packaging')->whereIn('fda_product_id', $fdaIds)->delete();
            DB::table('fda_product_pharm_classes')->whereIn('product_id_fk', $fdaIds)->delete();
            DB::table('fda_product_routes')->whereIn('product_id_fk', $fdaIds)->delete();
            FdaProduct::query()->whereIn('id', $fdaIds)->delete();
        }

        FdaOrganizationMatchReview::query()
            ->where('source', 'openfda_ndc')
            ->whereIn('canonical_name', ['OPENFDA TEST LABELER ALPHA', 'OPENFDA TEST LABELER BETA'])
            ->delete();

        FdaOrganization::query()
            ->whereIn('canonical_name', ['OPENFDA TEST LABELER ALPHA', 'OPENFDA TEST LABELER BETA'])
            ->delete();
    }
}
