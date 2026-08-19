<?php

namespace Tests\Feature\Catalog;

use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Support\Exceptions\AssortmentFromCatalog;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reversing a scanned NDC-encoded GTIN can still match several FDA packaging rows
 * while duplicates for one package remain. Which row an operator's correction lands
 * on must not depend on how MySQL felt about the plan that day.
 */
class AssortmentFromCatalogReversedGtinTest extends TestCase
{
    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $packagingIds = [];

    protected function tearDown(): void
    {
        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
        }
        if ($this->productIds !== []) {
            FdaProduct::query()->whereIn('id', $this->productIds)->delete();
        }
        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function a_reversed_gtin_without_direct_gtin_match_resolves_via_package_ndc(): void
    {
        $packageNdc = $this->uniquePackageNdc();
        $ndc10 = (string) preg_replace('/\D+/', '', $packageNdc);
        $scannedGtin = $this->ndcEncodedGtin('303', $ndc10);

        // Packaging stores a different GTIN; the match comes from reversing the scan to package NDC.
        $packaging = $this->createPackaging($packageNdc);

        $found = AssortmentFromCatalog::findPackagingByGtin($scannedGtin);

        $this->assertNotNull($found);
        $this->assertSame($packaging->id, $found->id);
    }

    private function createPackaging(string $packageNdc): FdaProductPackaging
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT Reversed GTIN '.uniqid(),
            'canonical_name' => 'SSOR CUT REVERSED GTIN '.uniqid(),
            'name' => 'SSOR CUT Reversed GTIN '.uniqid(),
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        $listing = FdaProduct::query()->create([
            'product_id' => 'SSOR-CUT-REV-'.uniqid(),
            'product_ndc' => substr($packageNdc, 0, 9),
            'name' => 'Reversed GTIN Fixture '.uniqid(),
            'fda_organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->productIds[] = (int) $listing->getKey();

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => $packageNdc,
            'gtin' => $this->uniqueGtin(),
            'ndc11' => Ndc::toNdc11($packageNdc),
            'is_active' => true,
        ]);
        $this->packagingIds[] = (int) $packaging->getKey();

        return $packaging;
    }

    private function ndcEncodedGtin(string $indicatorAndPrefix, string $ndc10): string
    {
        $body = $indicatorAndPrefix.$ndc10;
        $gtin = $body.Gtin::checkDigit($body);

        $this->assertSame($ndc10, Gtin::ndc10FromNdcEncodedGtin($gtin));

        return $gtin;
    }

    private function uniquePackageNdc(): string
    {
        do {
            $packageNdc = sprintf(
                '%04d-%04d-%02d',
                random_int(1000, 9999),
                random_int(0, 9999),
                random_int(0, 99),
            );
            $ndc11 = Ndc::toNdc11($packageNdc);
        } while (
            $ndc11 === null
            || FdaProductPackaging::query()
                ->where(fn ($query) => $query->where('package_ndc', $packageNdc)->orWhere('ndc11', $ndc11))
                ->exists()
        );

        return $packageNdc;
    }

    private function uniqueGtin(): string
    {
        do {
            $body = '0'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
            $gtin = $body.Gtin::checkDigit($body);
        } while (FdaProductPackaging::query()->where('gtin', $gtin)->exists());

        return $gtin;
    }
}
