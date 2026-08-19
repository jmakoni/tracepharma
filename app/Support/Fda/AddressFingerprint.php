<?php

namespace App\Support\Fda;

use App\Support\Places\UsState;

/**
 * Stable SHA-256 of a physical site address, shared by DECRS establishments,
 * WDD facilities, and catalog site mapping.
 */
final class AddressFingerprint
{
    public static function make(
        ?string $street,
        ?string $city,
        ?string $stateProvince,
        ?string $postalCode,
        ?string $countryCode = null,
        ?string $fullAddress = null,
    ): string {
        $streetNorm = self::normalizePart($street);
        $cityNorm = self::normalizePart($city);
        $stateNorm = self::normalizeState($stateProvince);
        $postalNorm = self::normalizePostal($postalCode, $countryCode);
        $countryNorm = self::normalizeCountry($countryCode);

        if ($streetNorm === '' && $cityNorm === '' && $stateNorm === '' && $postalNorm === '') {
            $fallback = self::normalizePart($fullAddress);

            return hash('sha256', $fallback);
        }

        return hash('sha256', implode('|', [$streetNorm, $cityNorm, $stateNorm, $postalNorm, $countryNorm]));
    }

    /**
     * @param  array{street_address: ?string, city: ?string, state_province: ?string, postal_code: ?string, country_code: ?string, full_address: string}  $parsed
     */
    public static function fromParsed(array $parsed): string
    {
        return self::make(
            $parsed['street_address'] ?? null,
            $parsed['city'] ?? null,
            $parsed['state_province'] ?? null,
            $parsed['postal_code'] ?? null,
            $parsed['country_code'] ?? null,
            $parsed['full_address'] ?? null,
        );
    }

    public static function fromWdd(?string $street, ?string $city, ?string $state, ?string $zip): string
    {
        return self::make($street, $city, $state, $zip, 'US');
    }

    private static function normalizePart(?string $value): string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\bU\.?S\.?A?\.?\b/', 'US', $value) ?? $value;
        $value = preg_replace('/[^A-Z0-9\s]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function normalizeState(?string $state): string
    {
        $normalized = UsState::normalize($state);

        if ($normalized !== null) {
            return $normalized;
        }

        return self::normalizePart($state);
    }

    private static function normalizePostal(?string $postal, ?string $countryCode): string
    {
        $postal = trim((string) $postal);

        if ($postal === '') {
            return '';
        }

        $country = self::normalizeCountry($countryCode);

        if ($country === 'US' || $country === '') {
            $digits = preg_replace('/\D/', '', $postal) ?? '';

            return substr($digits, 0, 5);
        }

        return self::normalizePart($postal);
    }

    private static function normalizeCountry(?string $country): string
    {
        $country = strtoupper(trim((string) $country));

        return match ($country) {
            'USA', 'UNITED STATES', 'US' => 'US',
            default => $country,
        };
    }
}
