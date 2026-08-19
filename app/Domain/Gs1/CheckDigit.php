<?php

declare(strict_types=1);

namespace App\Domain\Gs1;

use InvalidArgumentException;

/**
 * GS1 Mod-10 check digit (GTIN, SSCC, and related keys).
 */
final class CheckDigit
{
    /**
     * Compute the GS1 Mod-10 check digit for a numeric body (without check digit).
     */
    public static function mod10(string $bodyWithoutCheck): string
    {
        $digits = preg_replace('/\D+/', '', $bodyWithoutCheck) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('No digits provided for GS1 check digit.');
        }

        $total = 0;

        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $total += ((int) $digit) * ($i % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - ($total % 10)) % 10);
    }
}
