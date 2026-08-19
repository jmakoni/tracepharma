<?php

namespace App\Actions\OpenFda;

use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;

/**
 * Upsert FdaProductPackaging rows from the openFDA Drugs@FDA dataset's
 * `openfda.package_ndc` arrays.
 *
 * Drugs@FDA lists package NDCs at the application level, so a single result
 * can carry multiple `openfda.product_ndc` values. This action never
 * invents new `FdaProduct` rows — the NDC directory import
 * ({@see ImportOpenFdaNdcProducts}) remains the source of truth for
 * products. It only fills in packaging (and derived catalog products) for
 * product_ndcs that already exist, matching each package_ndc to the
 * product_ndc it belongs to via the "product_ndc-package_ndc" prefix
 * convention.
 */
final class ImportOpenFdaDrugsFdaPackages
{
    /**
     * @var array<string, FdaProduct|null>
     */
    private array $fdaProductsByNdc = [];

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  (callable(): void)|null  $onProgress
     * @return array{packaging_upserted: int, packaging_skipped_empty: int, skipped_no_fda_product: int, products_matched: int, errors: int}
     */
    public function handle(array $results, ?callable $onProgress = null): array
    {
        $counts = [
            'packaging_upserted' => 0,
            'packaging_skipped_empty' => 0,
            'skipped_no_fda_product' => 0,
            'products_matched' => 0,
            'errors' => 0,
        ];

        foreach ($results as $result) {
            try {
                $this->importOne($result, $counts);
            } catch (\Throwable $e) {
                $counts['errors']++;
                report($e);
            }

            $onProgress?->__invoke();
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array{packaging_upserted: int, packaging_skipped_empty: int, skipped_no_fda_product: int, products_matched: int, errors: int}  $counts
     */
    private function importOne(array $result, array &$counts): void
    {
        $openfda = is_array($result['openfda'] ?? null) ? $result['openfda'] : [];

        $productNdcs = $this->normalizeStrings($openfda['product_ndc'] ?? null);
        $packageNdcs = $this->normalizeStrings($openfda['package_ndc'] ?? null);

        if ($productNdcs === [] || $packageNdcs === []) {
            return;
        }

        /** @var array<string, FdaProduct> $matchedProducts product_ndc => FdaProduct */
        $matchedProducts = [];

        foreach ($productNdcs as $productNdc) {
            $fdaProduct = $this->findFdaProduct($productNdc);

            if ($fdaProduct !== null) {
                $matchedProducts[$productNdc] = $fdaProduct;
                $counts['products_matched']++;
            }
        }

        if ($matchedProducts === []) {
            $counts['skipped_no_fda_product'] += count($packageNdcs);

            return;
        }

        foreach ($packageNdcs as $packageNdc) {
            try {
                $this->importPackage(
                    $packageNdc,
                    $productNdcs,
                    $matchedProducts,
                    $counts
                );
            } catch (\Throwable $e) {
                $counts['errors']++;
                report($e);
            }
        }
    }

    /**
     * @param  array<int, string>  $productNdcs
     * @param  array<string, FdaProduct>  $matchedProducts
     * @param  array{packaging_upserted: int, packaging_skipped_empty: int, skipped_no_fda_product: int, products_matched: int, errors: int}  $counts
     */
    private function importPackage(
        string $packageNdc,
        array $productNdcs,
        array $matchedProducts,
        array &$counts
    ): void {
        if ($packageNdc === '') {
            $counts['packaging_skipped_empty']++;

            return;
        }

        $ownerProductNdc = $this->matchOwnerProductNdc($packageNdc, $productNdcs, $matchedProducts);

        if ($ownerProductNdc === null) {
            $counts['skipped_no_fda_product']++;

            return;
        }

        $fdaProduct = $matchedProducts[$ownerProductNdc];
        $existing = FdaProductPackaging::query()->where('package_ndc', $packageNdc)->first();
        $gtin = $existing?->gtin ?: Gtin::fromPackageNdc($packageNdc);
        $ndc11 = $existing?->ndc11 ?: Ndc::toNdc11($packageNdc);

        if ($gtin !== null && FdaProductPackaging::query()
            ->where('gtin', $gtin)
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))
            ->exists()) {
            $gtin = $existing?->gtin;
        }

        if ($ndc11 !== null && FdaProductPackaging::query()
            ->where('ndc11', $ndc11)
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))
            ->exists()) {
            $ndc11 = $existing?->ndc11;
        }

        $packaging = $existing ?? FdaProductPackaging::query()->firstOrNew(['package_ndc' => $packageNdc]);
        $packaging->fillFromFda(array_filter([
            'fda_product_id' => $fdaProduct->id,
            'description' => $existing?->description,
            'gtin' => $gtin,
            'ndc11' => $ndc11,
        ], static fn (mixed $value): bool => $value !== null));

        $counts['packaging_upserted']++;
    }

    /**
     * Determine which product_ndc "owns" a package_ndc.
     *
     * Drugs@FDA lists packages at the application level, so an application can mix
     * packages from several product_ndcs of which only some exist as FdaProducts.
     * Attaching a package to the sole matched product would file it under a labeler
     * product it does not belong to, so ownership is required: the package must be
     * spelled under the product_ndc that claims it, and that product_ndc must have
     * been matched. Anything else is left for the NDC directory import.
     *
     * @param  array<int, string>  $productNdcs
     * @param  array<string, FdaProduct>  $matchedProducts
     */
    private function matchOwnerProductNdc(string $packageNdc, array $productNdcs, array $matchedProducts): ?string
    {
        foreach ($productNdcs as $productNdc) {
            if (Ndc::productOwnsPackage($productNdc, $packageNdc)) {
                return isset($matchedProducts[$productNdc]) ? $productNdc : null;
            }
        }

        return null;
    }

    private function findFdaProduct(string $productNdc): ?FdaProduct
    {
        if (! array_key_exists($productNdc, $this->fdaProductsByNdc)) {
            $this->fdaProductsByNdc[$productNdc] = FdaProduct::query()
                ->where('product_ndc', $productNdc)
                ->first();
        }

        return $this->fdaProductsByNdc[$productNdc];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStrings(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        $normalized = [];

        foreach ($values as $item) {
            $string = $this->nullableString($item);

            if ($string !== null && ! in_array($string, $normalized, true)) {
                $normalized[] = $string;
            }
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
