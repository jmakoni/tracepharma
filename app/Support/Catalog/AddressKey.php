<?php

namespace App\Support\Catalog;

use App\Support\Places\UsState;

/**
 * Builds a stable comparison key for street + city + state address triples.
 */
final class AddressKey
{
    public static function make(?string $street, ?string $city, ?string $state): ?string
    {
        $streetNorm = self::normalizePart($street);
        $cityNorm = self::normalizePart($city);
        $stateNorm = UsState::normalize($state)
            ?? (trim((string) $state) !== '' ? strtoupper(substr(trim((string) $state), 0, 2)) : '');

        if ($streetNorm === '' && $cityNorm === '' && $stateNorm === '') {
            return null;
        }

        return $streetNorm.'|'.$cityNorm.'|'.$stateNorm;
    }

    private static function normalizePart(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9\s]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
