<?php

namespace App\Rules;

use App\Support\Gs1\Gtin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A GS1 Global Location Number: exactly 13 digits with a valid GS1 Mod-10 check digit.
 *
 * GLNs reach authored EPCIS verbatim — on destinationList, SBDH sender/receiver and
 * the location vocabulary — so 13 digits that merely fit the column would ship a
 * location no trading partner can resolve. Blank passes; pair with `required` when
 * the field is mandatory.
 */
final class ValidGln implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::isBlank($value)) {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            $fail('The :attribute must be a 13-digit GS1 GLN.');

            return;
        }

        $digits = self::digits($value);

        if (strlen($digits) !== 13) {
            $fail('The :attribute must be exactly 13 digits.');

            return;
        }

        if (! self::hasValidCheckDigit($digits)) {
            $fail('The :attribute is not a valid GS1 GLN (check digit failed).');
        }
    }

    /**
     * The normalized 13-digit GLN, or null when the value is not one.
     */
    public static function normalize(mixed $value): ?string
    {
        if (self::isBlank($value) || (! is_string($value) && ! is_numeric($value))) {
            return null;
        }

        $digits = self::digits($value);

        if (strlen($digits) !== 13 || ! self::hasValidCheckDigit($digits)) {
            return null;
        }

        return $digits;
    }

    private static function hasValidCheckDigit(string $gln13): bool
    {
        return Gtin::checkDigit(substr($gln13, 0, 12)) === substr($gln13, 12, 1);
    }

    private static function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
