<?php

namespace App\Support\Gs1;

/**
 * HIPAA NDC-11 helpers for pharmaceutical product identity.
 *
 * Canonical form is 11 digits: labeler (5) + product (4) + package (2).
 * Dashed NDCs are normalized using segment-length rules (4-4-2, 5-3-2, 5-4-1).
 */
final class Ndc
{
    /**
     * Strip non-digit characters from an NDC string.
     */
    public static function digits(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Normalize an NDC to canonical 11-digit NDC11, or null when not recoverable.
     *
     * A bare 10-digit NDC carries no segment boundaries, so 4-4-2 / 5-3-2 / 5-4-1
     * cannot be told apart and left-padding would silently invent a labeler code.
     * Such values return null; use {@see ndc11CandidatesFromTenDigits()} to search
     * every spelling, or pass the dashed package NDC instead.
     */
    public static function toNdc11(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (str_contains($value, '-')) {
            return self::fromDashed($value);
        }

        $digits = self::digits($value);

        return strlen($digits) === 11 ? $digits : null;
    }

    /**
     * Canonical NDC-11 for a product, preferring the package NDC over the product NDC.
     */
    public static function derive(?string $packageNdc, ?string $ndc = null): ?string
    {
        return self::toNdc11($packageNdc) ?? self::toNdc11($ndc);
    }

    /**
     * Canonical 9-digit labeler + product prefix of a two-segment FDA product NDC.
     *
     * Uses the same segment-length rules as {@see toNdc11()}: 4-4 pads the labeler,
     * 5-3 pads the product, 5-4 is already canonical.
     */
    public static function toProductNdc9(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $segments = array_map(
            fn (string $segment): string => self::digits($segment),
            explode('-', trim($value)),
        );

        if (count($segments) !== 2) {
            return null;
        }

        [$labeler, $product] = $segments;

        if ($labeler === '' || $product === '') {
            return null;
        }

        $labelerLength = strlen($labeler);
        $productLength = strlen($product);

        if ($labelerLength === 4 && $productLength === 4) {
            $labeler = '0'.$labeler;
        } elseif ($labelerLength === 5 && $productLength === 3) {
            $product = '0'.$product;
        } elseif (! ($labelerLength === 5 && $productLength === 4)) {
            $labeler = str_pad($labeler, 5, '0', STR_PAD_LEFT);
            $product = str_pad($product, 4, '0', STR_PAD_LEFT);
        }

        $ndc9 = $labeler.$product;

        return strlen($ndc9) === 9 ? $ndc9 : null;
    }

    /**
     * True when the package NDC belongs to the given product NDC.
     *
     * Matches the FDA "product_ndc-package suffix" convention directly and, when the
     * two values are spelled with different segment lengths (4-4 product against a
     * 5-3-2 package, say), by comparing canonical labeler + product digits.
     */
    public static function productOwnsPackage(?string $productNdc, ?string $packageNdc): bool
    {
        if ($productNdc === null || $packageNdc === null) {
            return false;
        }

        $productNdc = trim($productNdc);
        $packageNdc = trim($packageNdc);

        if ($productNdc === '' || $packageNdc === '') {
            return false;
        }

        if (str_starts_with($packageNdc, $productNdc.'-')) {
            return true;
        }

        $ndc9 = self::toProductNdc9($productNdc);
        $ndc11 = self::toNdc11($packageNdc);

        return $ndc9 !== null && $ndc11 !== null && str_starts_with($ndc11, $ndc9);
    }

    /**
     * Every NDC-11 a bare 10-digit NDC could expand to (4-4-2, 5-3-2, 5-4-1).
     *
     * @return list<string>
     */
    public static function ndc11CandidatesFromTenDigits(?string $value): array
    {
        $digits = self::digits($value);

        if (strlen($digits) !== 10) {
            return [];
        }

        return array_values(array_unique([
            '0'.$digits,                                              // 4-4-2
            substr($digits, 0, 5).'0'.substr($digits, 5),              // 5-3-2
            substr($digits, 0, 9).'0'.substr($digits, 9),              // 5-4-1
        ]));
    }

    /**
     * Format NDC11 as 5-4-2 dashed display (#####-####-##).
     *
     * Prefer {@see formatPackageDisplay()} for operator-facing FDA package spelling.
     */
    public static function formatDisplay(?string $value): ?string
    {
        $ndc11 = self::toNdc11($value);

        if ($ndc11 === null) {
            return null;
        }

        return sprintf(
            '%s-%s-%s',
            substr($ndc11, 0, 5),
            substr($ndc11, 5, 4),
            substr($ndc11, 9, 2),
        );
    }

    /**
     * Operator-facing FDA package NDC (10-digit dashed when recoverable).
     *
     * Priority:
     * 1. Authoritative listing / assortment package_ndc (as-is)
     * 2. Value already in FDA 10-digit dashed shape (4-4-2 / 5-3-2 / 5-4-1) — as-is
     * 3. HIPAA NDC-11, undashed digits, or HIPAA 5-4-2 dashed — reverse the padded segment
     */
    public static function formatPackageDisplay(?string $value, ?string $authoritativePackageNdc = null): ?string
    {
        if ($authoritativePackageNdc !== null && trim($authoritativePackageNdc) !== '') {
            return trim($authoritativePackageNdc);
        }

        if ($value === null || trim($value) === '') {
            return null;
        }

        $trimmed = trim($value);

        if (str_contains($trimmed, '-') && self::isFdaTenDigitDashed($trimmed)) {
            return $trimmed;
        }

        $ndc11 = self::toNdc11($trimmed);

        if ($ndc11 === null) {
            return null;
        }

        return self::hipaaReverseToFdaDashed($ndc11);
    }

    /**
     * True when the value is already an FDA label-style 10-digit dashed NDC.
     */
    private static function isFdaTenDigitDashed(string $value): bool
    {
        $segments = array_map(
            fn (string $segment): string => self::digits($segment),
            explode('-', trim($value)),
        );

        if (count($segments) !== 3) {
            return false;
        }

        [$labeler, $product, $package] = $segments;
        $labelerLength = strlen($labeler);
        $productLength = strlen($product);
        $packageLength = strlen($package);
        $total = $labelerLength + $productLength + $packageLength;

        if ($total !== 10) {
            return false;
        }

        return ($labelerLength === 4 && $productLength === 4 && $packageLength === 2)
            || ($labelerLength === 5 && $productLength === 3 && $packageLength === 2)
            || ($labelerLength === 5 && $productLength === 4 && $packageLength === 1);
    }

    /**
     * Compare two NDC values for canonical equality.
     */
    public static function equals(?string $a, ?string $b): bool
    {
        $left = self::toNdc11($a);
        $right = self::toNdc11($b);

        if ($left === null || $right === null) {
            return false;
        }

        return $left === $right;
    }

    /**
     * Dashed package-NDC spellings that may appear in FDA / openFDA listings
     * for a canonical NDC-11 (4-4-2, 5-3-2, 5-4-1, and 5-4-2).
     *
     * @return list<string>
     */
    public static function packageNdcCandidates(?string $value): array
    {
        $ndc11 = self::toNdc11($value);
        if ($ndc11 === null) {
            return [];
        }

        $labeler = substr($ndc11, 0, 5);
        $product = substr($ndc11, 5, 4);
        $package = substr($ndc11, 9, 2);

        $candidates = [
            $labeler.'-'.$product.'-'.$package, // 5-4-2
        ];

        if (str_starts_with($labeler, '0')) {
            $candidates[] = substr($labeler, 1).'-'.$product.'-'.$package; // 4-4-2
        }

        if (str_starts_with($product, '0')) {
            $candidates[] = $labeler.'-'.substr($product, 1).'-'.$package; // 5-3-2
        }

        if (str_starts_with($package, '0')) {
            $candidates[] = $labeler.'-'.$product.'-'.substr($package, 1); // 5-4-1
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Reverse HIPAA NDC-11 padding to the FDA 10-digit dashed form.
     * Exactly one segment was padded; try labeler, then product, then package.
     */
    private static function hipaaReverseToFdaDashed(string $ndc11): string
    {
        $labeler = substr($ndc11, 0, 5);
        $product = substr($ndc11, 5, 4);
        $package = substr($ndc11, 9, 2);

        if (str_starts_with($labeler, '0')) {
            return substr($labeler, 1).'-'.$product.'-'.$package;
        }

        if (str_starts_with($product, '0')) {
            return $labeler.'-'.substr($product, 1).'-'.$package;
        }

        if (str_starts_with($package, '0')) {
            return $labeler.'-'.$product.'-'.substr($package, 1);
        }

        return $labeler.'-'.$product.'-'.$package;
    }

    /**
     * Only the three FDA package spellings (4-4-2, 5-3-2, 5-4-1) and the canonical
     * HIPAA 5-4-2 are read. Any other segment shape is left alone: padding it would
     * invent digits the source never carried, the same reason a bare 10-digit NDC
     * is refused in {@see toNdc11()}.
     */
    private static function fromDashed(string $value): ?string
    {
        $segments = array_map(
            fn (string $segment): string => self::digits($segment),
            explode('-', trim($value)),
        );

        if (count($segments) !== 3) {
            return null;
        }

        [$labeler, $product, $package] = $segments;

        $shape = strlen($labeler).'-'.strlen($product).'-'.strlen($package);

        return match ($shape) {
            '4-4-2' => '0'.$labeler.$product.$package,
            '5-3-2' => $labeler.'0'.$product.$package,
            '5-4-1' => $labeler.$product.'0'.$package,
            '5-4-2' => $labeler.$product.$package,
            default => null,
        };
    }
}
