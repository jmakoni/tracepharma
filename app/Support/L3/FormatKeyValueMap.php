<?php

declare(strict_types=1);

namespace App\Support\L3;

/**
 * Shared "N/A for empty values" formatting for Guardian L3 key/value bags
 * (`lot_control_data`, container `fields`) rendered via KeyValueEntry.
 */
final class FormatKeyValueMap
{
    /**
     * @param  array<string, mixed>|null  $map
     * @return array<string, string>
     */
    public static function withNaPlaceholders(?array $map): array
    {
        if ($map === null) {
            return [];
        }

        $formatted = [];

        foreach ($map as $key => $value) {
            $formatted[(string) $key] = self::formatValue($value);
        }

        return $formatted;
    }

    private static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'N/A';
        }

        if (is_scalar($value)) {
            $stringValue = trim((string) $value);

            return $stringValue === '' ? 'N/A' : $stringValue;
        }

        $encoded = json_encode($value);

        return filled($encoded) ? $encoded : 'N/A';
    }
}
