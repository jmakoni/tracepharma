<?php

declare(strict_types=1);

namespace App\Support\Config;

/**
 * Boolean cast for compliance kill-switches read from the environment.
 *
 * `(bool) env('FLAG', true)` fails open: a bare `FLAG=` line in .env yields an empty
 * string, which casts to false and silently turns off an enforcement gate nobody meant
 * to disable. An absent, empty or unparseable value keeps the safe default instead, so a
 * gate can only be lifted by writing a value that means false.
 */
final class SafetyGate
{
    public static function enabled(string $key, bool $default = true): bool
    {
        $raw = env($key);

        if (is_bool($raw)) {
            return $raw;
        }

        if ($raw === null || trim((string) $raw) === '') {
            return $default;
        }

        return filter_var(trim((string) $raw), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
