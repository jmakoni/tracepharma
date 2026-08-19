<?php

namespace App\Support\Gs1;

use App\Domain\Gs1\CheckDigit;

/**
 * GS1 GTIN-14 helpers for pharmaceutical packaging.
 *
 * Prefer a labeler's published UPC when present and valid. Otherwise encode the
 * 10-digit package NDC as GTIN-14 using the common US "NDC in GTIN" form:
 * body `003` + 10 NDC digits + GS1 Mod-10 check digit.
 *
 * NDC-encoded GTINs may differ from a labeler's printed GS1 company-prefix GTIN.
 */
final class Gtin
{
    /**
     * Prefer a valid UPC-derived GTIN-14; otherwise derive from package NDC.
     */
    public static function forPackaging(?string $upc, string $packageNdc): ?string
    {
        $fromUpc = self::fromUpc($upc);

        if ($fromUpc !== null) {
            return $fromUpc;
        }

        return self::fromPackageNdc($packageNdc);
    }

    /**
     * Encode a 10-digit package NDC as GTIN-14 (`003` + NDC10 + check).
     */
    public static function fromPackageNdc(string $packageNdc): ?string
    {
        $digits = preg_replace('/\D+/', '', $packageNdc) ?? '';

        if (strlen($digits) !== 10) {
            return null;
        }

        $body = '003'.$digits;

        return $body.self::checkDigit($body);
    }

    /**
     * Normalize a GTIN-8, UPC-A (12), EAN-13 or GTIN-14 to GTIN-14 and validate the
     * check digit.
     *
     * Only real GS1 structure lengths are accepted. An 11-digit value is a UPC-A with its
     * check digit left off, so zero-padding it to 14 reads the last product digit as the
     * check digit: roughly one in ten such strings passes Mod-10 by coincidence and
     * becomes a GTIN for a different package.
     */
    public static function fromUpc(?string $upc): ?string
    {
        if ($upc === null || $upc === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $upc) ?? '';

        if (! in_array(strlen($digits), [8, 12, 13, 14], true)) {
            return null;
        }

        $gtin14 = str_pad($digits, 14, '0', STR_PAD_LEFT);
        $body = substr($gtin14, 0, 13);
        $provided = substr($gtin14, 13, 1);

        if ($provided !== self::checkDigit($body)) {
            return null;
        }

        return $gtin14;
    }

    /**
     * Extract the 10-digit NDC from a GS1 US NDC-encoded GTIN-14.
     *
     * Form: indicator (1) + "03" + NDC10 (10) + check (1).
     * Returns null when not 14 digits, check digit invalid, or not NDC-encoded (positions 1-2 after indicator are not "03").
     */
    public static function ndc10FromNdcEncodedGtin(?string $gtin): ?string
    {
        if ($gtin === null || $gtin === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $gtin) ?? '';

        if (strlen($digits) !== 14) {
            return null;
        }

        $body = substr($digits, 0, 13);
        $check = substr($digits, 13, 1);

        if ($check !== self::checkDigit($body)) {
            return null;
        }

        if (substr($digits, 1, 2) !== '03') {
            return null;
        }

        return substr($digits, 3, 10);
    }

    /**
     * Packaging-level indicator digit (first position) of a GTIN-14.
     *
     * 0 marks the base trade item and 1-8 the packaging levels above it, so the
     * larger digit of two GTINs is the outer pack. 9 is reserved for variable
     * measure trade items and carries no level, so it reads as unknown.
     */
    public static function packagingIndicator(?string $gtin): ?int
    {
        if ($gtin === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $gtin) ?? '';

        if (strlen($digits) !== 14) {
            return null;
        }

        $indicator = (int) $digits[0];

        return $indicator === 9 ? null : $indicator;
    }

    /**
     * GS1 Mod-10 check digit for a numeric body (without check digit).
     */
    public static function checkDigit(string $bodyWithoutCheck): string
    {
        return CheckDigit::mod10($bodyWithoutCheck);
    }
}
