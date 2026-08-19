<?php

namespace App\Support\Catalog;

/**
 * GS1 identifier helpers for catalog master data imports.
 */
final class Gln
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) > 13) {
            $digits = substr($digits, -13);
        }

        if (strlen($digits) < 11) {
            return null;
        }

        return str_pad($digits, 13, '0', STR_PAD_LEFT);
    }

    public static function normalizePostalCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) <= 5) {
            return str_pad($digits, 5, '0', STR_PAD_LEFT);
        }

        if (strlen($digits) < 9) {
            return str_pad($digits, 9, '0', STR_PAD_LEFT);
        }

        return substr($digits, 0, 9);
    }

    /**
     * Extract location reference from urn:epc:id:sgln:PREFIX.LOC.EXT
     */
    public static function locationCodeFromSgln(?string $sgln): ?string
    {
        if ($sgln === null || $sgln === '') {
            return null;
        }

        if (! preg_match('/urn:epc:id:sgln:[^.]+\.([^.]+)\./i', $sgln, $matches)) {
            return null;
        }

        $code = trim($matches[1]);

        return $code !== '' ? $code : null;
    }
}
