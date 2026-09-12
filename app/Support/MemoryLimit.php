<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PHP refuses to lower memory_limit below current usage (PHP 8+). After Dompdf
 * renders a large report we may still hold ~150MB+ when FPM's limit is 128M.
 */
final class MemoryLimit
{
    public static function raise(string $limit): string
    {
        $previous = (string) ini_get('memory_limit');
        ini_set('memory_limit', $limit);

        return $previous;
    }

    public static function restore(string $previousLimit): void
    {
        if ($previousLimit === '') {
            return;
        }

        $targetBytes = self::toBytes($previousLimit);

        if ($targetBytes > 0 && memory_get_usage(true) > $targetBytes) {
            return;
        }

        @ini_set('memory_limit', $previousLimit);
    }

    public static function toBytes(string $limit): int
    {
        $limit = trim($limit);

        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $unit = strtolower(substr($limit, -1));

        if (in_array($unit, ['g', 'm', 'k'], true)) {
            $value = (int) substr($limit, 0, -1);

            return match ($unit) {
                'g' => $value * 1024 * 1024 * 1024,
                'm' => $value * 1024 * 1024,
                'k' => $value * 1024,
            };
        }

        return (int) $limit;
    }
}
