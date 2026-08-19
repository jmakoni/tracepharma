<?php

namespace Tests\Feature\Fda;

use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FdaPackagingHygieneTest extends TestCase
{
    /** @var list<int> */
    private array $organizationIds = [];

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

        if ($this->organizationIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->organizationIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function dedupe_deactivates_the_alternate_spelling_of_the_same_ndc11(): void
    {
        $packageNdc = $this->uniquePackageNdc();
        $fiveFourTwo = Ndc::packageNdcCandidates($packageNdc)[0];
        $this->assertNotSame($packageNdc, $fiveFourTwo);
        $this->assertSame(Ndc::toNdc11($packageNdc), Ndc::toNdc11($fiveFourTwo));

        $listing = $this->listing();
        $keeper = $this->packaging($listing, $packageNdc, $this->gtinFor($packageNdc), Ndc::toNdc11($packageNdc));
        $twin = $this->packaging($listing, $fiveFourTwo, $this->uniqueGtin(), null);

        $this->artisan('fda:dedupe-package-ndc')->assertSuccessful();

        $this->assertTrue((bool) $keeper->fresh()->is_active);
        $this->assertFalse((bool) $twin->fresh()->is_active);
        $this->assertSame(Ndc::toNdc11($packageNdc), $keeper->fresh()->ndc11);
        $this->assertNull($twin->fresh()->ndc11);
    }

    #[Test]
    public function backfill_assigns_ndc11_from_package_ndc(): void
    {
        $packageNdc = $this->uniquePackageNdc();
        $listing = $this->listing();
        $row = $this->packaging($listing, $packageNdc, $this->uniqueGtin(), null);

        $this->artisan('fda:backfill-ndc11')->assertSuccessful();

        $this->assertSame(Ndc::toNdc11($packageNdc), $row->fresh()->ndc11);
    }

    #[Test]
    public function dry_run_dedupe_does_not_write(): void
    {
        $packageNdc = $this->uniquePackageNdc();
        $fiveFourTwo = Ndc::packageNdcCandidates($packageNdc)[0];
        $listing = $this->listing();
        $keeper = $this->packaging($listing, $packageNdc, $this->uniqueGtin(), Ndc::toNdc11($packageNdc));
        $twin = $this->packaging($listing, $fiveFourTwo, $this->uniqueGtin(), null);

        $this->artisan('fda:dedupe-package-ndc', ['--dry-run' => true])
            ->expectsOutputToContain(sprintf('fda_product_packaging %d: is_active => false', $twin->getKey()))
            ->assertSuccessful();

        $this->assertTrue((bool) $keeper->fresh()->is_active);
        $this->assertTrue((bool) $twin->fresh()->is_active);
    }

    private function listing(): FdaProduct
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR FDA Hygiene',
            'canonical_name' => 'SSOR FDA HYGIENE',
            'name' => 'SSOR FDA Hygiene',
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->organizationIds[] = $org->id;

        $product = FdaProduct::query()->create([
            'product_id' => 'SSOR-HYGIENE-'.uniqid(),
            'product_ndc' => sprintf('%05d-%03d', random_int(80000, 89999), random_int(0, 999)),
            'name' => 'SSOR Hygiene Listing',
            'fda_organization_id' => $org->id,
            'is_active' => true,
        ]);
        $this->productIds[] = $product->id;

        return $product;
    }

    private function packaging(FdaProduct $listing, string $packageNdc, string $gtin, ?string $ndc11): FdaProductPackaging
    {
        $row = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'package_ndc' => $packageNdc,
            'gtin' => $gtin,
            'ndc11' => $ndc11,
            'is_active' => true,
        ]);
        $this->packagingIds[] = $row->id;

        return $row;
    }

    private function gtinFor(string $packageNdc): string
    {
        $gtin = Gtin::fromPackageNdc($packageNdc);
        $this->assertNotNull($gtin);

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
            $gtin = Gtin::fromPackageNdc($packageNdc);
            $candidates = Ndc::packageNdcCandidates($packageNdc);
        } while (
            $ndc11 === null
            || $gtin === null
            || count($candidates) < 2
            || FdaProductPackaging::query()
                ->where(fn ($query) => $query->whereIn('package_ndc', $candidates)
                    ->orWhere('ndc11', $ndc11)
                    ->orWhere('gtin', $gtin))
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
