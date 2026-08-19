<?php

namespace App\Actions\OpenFda;

use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductActiveIngredient;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaProductPharmClass;
use App\Models\Fda\FdaProductRoute;
use App\Support\Catalog\DisplayName;
use App\Support\Fda\FdaOrganizationSlugIndex;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use App\Support\PartnerSlug;

/**
 * Upsert FdaProduct records and packaging from an openFDA NDC directory payload.
 */
final class ImportOpenFdaNdcProducts
{
    private const PHARM_CLASS_KEYS = [
        'pharm_class_epc',
        'pharm_class_moa',
        'pharm_class_pe',
        'pharm_class_cs',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  (callable(): void)|null  $onProgress
     * @return array{fda_upserted: int, packaging_upserted: int, org_linked: int, missing_org: int}
     */
    public function handle(array $results, ?callable $onProgress = null): array
    {
        $counts = [
            'fda_upserted' => 0,
            'packaging_upserted' => 0,
            'org_linked' => 0,
            'missing_org' => 0,
        ];

        $orgIdsBySlug = FdaOrganizationSlugIndex::map();

        foreach ($results as $result) {
            $productId = trim((string) ($result['product_id'] ?? ''));

            if ($productId === '') {
                $onProgress?->__invoke();

                continue;
            }

            try {
                $this->importOne($result, $productId, $orgIdsBySlug, $counts);
            } catch (\Throwable $e) {
                $counts['errors'] = ($counts['errors'] ?? 0) + 1;
                report($e);
            }

            $onProgress?->__invoke();
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, int>  $orgIdsBySlug
     * @param  array{fda_upserted: int, packaging_upserted: int, org_linked: int, missing_org: int}  $counts
     */
    private function importOne(array $result, string $productId, array $orgIdsBySlug, array &$counts): void
    {
        $openfda = is_array($result['openfda'] ?? null) ? $result['openfda'] : [];
        $labelerName = DisplayName::clean(trim((string) ($result['labeler_name'] ?? ''))) ?? '';
        $brandName = DisplayName::clean($this->nullableString($result['brand_name'] ?? null));
        $genericName = DisplayName::clean($this->nullableString($result['generic_name'] ?? null));
        $productNdc = trim((string) ($result['product_ndc'] ?? ''));

        $organizationId = null;

        if ($labelerName !== '') {
            $organizationId = $orgIdsBySlug[PartnerSlug::from($labelerName)] ?? null;

            if ($organizationId !== null) {
                $counts['org_linked']++;
            } else {
                $counts['missing_org']++;
            }
        }

        $fdaProduct = FdaProduct::query()->firstOrNew(['product_id' => $productId]);
        $fdaProduct->fillFromFda([
            'product_ndc' => $productNdc,
            'generic_name' => $genericName,
            'brand_name' => $brandName,
            'brand_name_base' => DisplayName::clean($this->nullableString($result['brand_name_base'] ?? null)),
            'fda_organization_id' => $organizationId,
            'marketing_category' => $this->nullableString($result['marketing_category'] ?? null),
            'application_number' => ($an = $this->nullableString($result['application_number'] ?? null)) !== null
                ? mb_substr($an, 0, 50)
                : null,
            'dosage_form' => ($df = $this->nullableString($result['dosage_form'] ?? null)) !== null
                ? mb_substr($df, 0, 100)
                : null,
            'product_type' => ($pt = $this->nullableString($result['product_type'] ?? null)) !== null
                ? mb_substr($pt, 0, 100)
                : null,
            'dea_schedule' => ($ds = $this->nullableString($result['dea_schedule'] ?? null)) !== null
                ? mb_substr($ds, 0, 10)
                : null,
            'finished' => (bool) ($result['finished'] ?? true),
            'marketing_start_date' => $this->parseDate($result['marketing_start_date'] ?? null),
            'listing_expiration_date' => $this->parseDate($result['listing_expiration_date'] ?? null),
            'spl_id' => $this->nullableString($result['spl_id'] ?? null),
            'spl_set_id' => $this->firstIfArray($openfda['spl_set_id'] ?? null),
        ]);

        $counts['fda_upserted']++;

        $activeIngredients = is_array($result['active_ingredients'] ?? null) ? $result['active_ingredients'] : [];

        foreach ($activeIngredients as $ingredient) {
            $name = DisplayName::clean($this->nullableString($ingredient['name'] ?? null));

            if ($name === null) {
                continue;
            }

            FdaProductActiveIngredient::query()->firstOrCreate([
                'product_id_fk' => $fdaProduct->id,
                'name' => $name,
                'strength' => $this->nullableString($ingredient['strength'] ?? null),
            ]);
        }

        foreach ($this->collectPharmClasses($openfda) as $className) {
            FdaProductPharmClass::query()->firstOrCreate([
                'product_id_fk' => $fdaProduct->id,
                'class_name' => $className,
            ]);
        }

        foreach ($this->normalizeRoutes($result['route'] ?? null) as $routeName) {
            FdaProductRoute::query()->firstOrCreate([
                'product_id_fk' => $fdaProduct->id,
                'route_name' => $routeName,
            ]);
        }

        $upc = $this->firstIfArray($openfda['upc'] ?? null);

        $packaging = is_array($result['packaging'] ?? null) ? $result['packaging'] : [];
        // Product-level UPC maps to one GTIN; only prefer it when there is a single package.
        $packageUpc = count($packaging) === 1 ? $upc : null;

        foreach ($packaging as $pkg) {
            $packageNdc = trim((string) ($pkg['package_ndc'] ?? ''));

            if ($packageNdc === '') {
                continue;
            }

            $this->upsertPackaging(
                $packageNdc,
                $fdaProduct->id,
                [
                    'description' => $this->nullableString($pkg['description'] ?? null),
                    'marketing_start_date' => $this->parseDate($pkg['marketing_start_date'] ?? null),
                    'is_sample' => (bool) ($pkg['sample'] ?? false),
                ],
                $packageUpc,
            );

            $counts['packaging_upserted']++;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertPackaging(string $packageNdc, int $fdaProductId, array $attributes, ?string $upc): void
    {
        $existing = FdaProductPackaging::query()->where('package_ndc', $packageNdc)->first();
        $gtin = $existing?->gtin ?: Gtin::forPackaging($upc, $packageNdc);
        $ndc11 = $existing?->ndc11 ?: Ndc::toNdc11($packageNdc);

        if ($gtin !== null && $this->identifierOwnedByAnother('gtin', $gtin, $existing)) {
            $gtin = $existing?->gtin;
        }

        if ($ndc11 !== null && $this->identifierOwnedByAnother('ndc11', $ndc11, $existing)) {
            $ndc11 = $existing?->ndc11;
        }

        $packaging = $existing ?? FdaProductPackaging::query()->firstOrNew(['package_ndc' => $packageNdc]);
        $packaging->fillFromFda(array_filter([
            'fda_product_id' => $fdaProductId,
            'gtin' => $gtin,
            'ndc11' => $ndc11,
            ...$attributes,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function identifierOwnedByAnother(string $column, string $value, ?FdaProductPackaging $existing): bool
    {
        return FdaProductPackaging::query()
            ->where($column, $value)
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function collectPharmClasses(array $openfda): array
    {
        $classes = [];

        foreach (self::PHARM_CLASS_KEYS as $key) {
            $values = $openfda[$key] ?? null;

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                $name = $this->nullableString($value);

                if ($name !== null) {
                    $classes[$name] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * Normalize the openFDA `route` field, which may be a single string or
     * an array of strings depending on the record.
     *
     * @return array<int, string>
     */
    private function normalizeRoutes(mixed $route): array
    {
        if ($route === null) {
            return [];
        }

        $values = is_array($route) ? $route : [$route];

        $routes = [];

        foreach ($values as $value) {
            $name = $this->nullableString($value);

            if ($name !== null) {
                $routes[$name] = true;
            }
        }

        return array_keys($routes);
    }

    private function firstIfArray(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $this->nullableString($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (! preg_match('/^\d{8}$/', $value)) {
            return null;
        }

        return substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
    }
}
