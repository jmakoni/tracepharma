<?php

namespace App\Actions\Epcis;

use App\Models\Product;

/**
 * Resolve a tenant product by GTIN-14 or NDC-11 without creating rows.
 *
 * GTIN-14 carries the packaging level in its indicator digit, so an exact GTIN
 * match is always preferred: it is the only identifier that distinguishes a case
 * from the unit it contains. The NDC-11 hint is consulted next, and the
 * cross-indicator (company prefix + item reference) fallback only resolves when
 * exactly one product shares that packaging body — otherwise a case scan could
 * silently link to the unit product, or vice versa.
 */
final class ResolveProductFromIdentifier
{
    /** @var array<string, ?Product> */
    private static array $gtinCache = [];

    /** @var array<string, ?Product> */
    private static array $packagingBodyCache = [];

    /** @var array<string, ?Product> */
    private static array $ndcCache = [];

    public function handle(?string $gtin14 = null, ?string $ndc11 = null): ?Product
    {
        if (filled($gtin14)) {
            $exact = $this->exactByGtin((string) $gtin14);
            if ($exact !== null) {
                return $exact;
            }
        }

        if (filled($ndc11)) {
            $byNdc = $this->byNdc11((string) $ndc11);
            if ($byNdc !== null) {
                return $byNdc;
            }
        }

        if (filled($gtin14)) {
            return $this->byPackagingBody((string) $gtin14);
        }

        return null;
    }

    public static function clearCache(): void
    {
        self::$gtinCache = [];
        self::$packagingBodyCache = [];
        self::$ndcCache = [];
    }

    private function exactByGtin(string $gtin14): ?Product
    {
        $key = $this->cacheKey('gtin:'.$gtin14);

        if (! array_key_exists($key, self::$gtinCache)) {
            self::$gtinCache[$key] = Product::query()->where('gtin', $gtin14)->first();
        }

        return self::$gtinCache[$key];
    }

    private function byNdc11(string $ndc11): ?Product
    {
        $key = $this->cacheKey('ndc:'.$ndc11);

        if (! array_key_exists($key, self::$ndcCache)) {
            self::$ndcCache[$key] = Product::query()->where('ndc11', $ndc11)->first();
        }

        return self::$ndcCache[$key];
    }

    /**
     * Same GS1 company prefix + item reference at a different packaging indicator.
     *
     * Only resolves when the packaging body is unambiguous — when the tenant
     * carries both the case and the unit as separate products, guessing between
     * them would attach EPCs to the wrong packaging level.
     */
    private function byPackagingBody(string $gtin14): ?Product
    {
        if (strlen($gtin14) !== 14 || ! ctype_digit($gtin14)) {
            return null;
        }

        $key = $this->cacheKey('body:'.$gtin14);

        if (array_key_exists($key, self::$packagingBodyCache)) {
            return self::$packagingBodyCache[$key];
        }

        $body = substr($gtin14, 1, 12);

        $candidates = Product::query()
            ->whereRaw('SUBSTRING(gtin, 2, 12) = ?', [$body])
            ->orderBy('id')
            ->limit(2)
            ->get();

        return self::$packagingBodyCache[$key] = $candidates->count() === 1
            ? $candidates->first()
            : null;
    }

    private function cacheKey(string $identifier): string
    {
        $tenantId = 'central';
        if (function_exists('tenancy') && tenancy()->initialized && tenant() !== null) {
            $tenantId = (string) tenant('id');
        }

        return $tenantId.'|'.$identifier;
    }
}
