<?php

namespace App\Support\Fda;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

/**
 * FDA WDD/3PL reports emit US license expirations as m/d/Y or m-d-Y.
 * Carbon::parse() treats hyphenated values as d-m-Y and throws on day>12 months.
 */
final class FdaDate
{
    /**
     * @var list<string>
     */
    private const FORMATS = [
        'm-d-Y',
        'n-j-Y',
        'm/d/Y',
        'n/j/Y',
        'Y-m-d',
    ];

    public static function parse(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (self::FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($date === false || $date->format($format) !== $value) {
                continue;
            }

            return $date->startOfDay();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function toDateString(?string $value): ?string
    {
        return self::parse($value)?->toDateString();
    }

    public static function display(?string $value): ?string
    {
        $date = self::parse($value);

        if ($date !== null) {
            return $date->toFormattedDateString();
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
