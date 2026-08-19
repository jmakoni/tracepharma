<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Gs1\Gtin;
use App\Support\Gs1\Sgln;

class Gs1LocationNormalizer
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if ($gln = self::glnFromDigitalLink($value)) {
            return $gln;
        }

        if (str_starts_with($value, 'urn:epc:id:sgln:') || str_contains(strtolower($value), 'sgln')) {
            return self::sglnToGln($value);
        }

        if (preg_match('/^\d{13}$/', $value)) {
            return $value;
        }

        if (str_contains($value, ':')) {
            $stripped = last(explode(':', $value)) ?: $value;

            if ($gln = self::glnFromDigitalLink($stripped)) {
                return $gln;
            }

            return self::sglnToGln($stripped) ?? $stripped;
        }

        return self::sglnToGln($value) ?? $value;
    }

    private static function glnFromDigitalLink(string $value): ?string
    {
        if (preg_match('#(?:^|/)414/(\d{13})(?:/|$)#', $value, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private static function sglnToGln(string $value): ?string
    {
        $uri = $value;
        if (! str_starts_with(strtolower($value), 'urn:epc:id:sgln:')) {
            if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $value)) {
                $uri = 'urn:epc:id:sgln:'.$value;
            } elseif (preg_match('/urn:epc:id:sgln:([0-9.]+)/i', $value, $matches)) {
                $uri = 'urn:epc:id:sgln:'.$matches[1];
            }
        }

        $parsed = Sgln::fromUrn($uri);

        if ($parsed !== null) {
            return $parsed['gln'];
        }

        $normalizedGln = Sgln::normalizeGln($value);

        if ($normalizedGln !== null) {
            return $normalizedGln;
        }

        if (! preg_match('/^(\d+)\.(\d*)\.(\d+)$/', $value, $matches)
            && ! preg_match('/urn:epc:id:sgln:(\d+)\.(\d*)\.(\d+)$/i', $value, $matches)) {
            $digits = preg_replace('/\D/', '', $value) ?? '';

            if (strlen($digits) === 13) {
                return $digits;
            }

            if (strlen($digits) === 12) {
                return $digits.Gtin::checkDigit($digits);
            }

            return null;
        }

        $companyPrefix = $matches[1];
        $locationReference = $matches[2];
        $baseLength = strlen($companyPrefix) + strlen($locationReference);

        if ($baseLength > 12) {
            return null;
        }

        $locationReference = str_pad($locationReference, 12 - strlen($companyPrefix), '0', STR_PAD_LEFT);
        $base = $companyPrefix.$locationReference;

        if (strlen($base) !== 12) {
            return null;
        }

        return $base.Gtin::checkDigit($base);
    }
}
