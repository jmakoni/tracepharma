<?php

namespace App\Support\Catalog;

use Illuminate\Support\Str;

/**
 * Cleans and title-cases display names from upstream feeds.
 *
 * Strips repeated leading "- " / ": " style prefixes, then applies
 * {@see Str::title()} (Laravel's ucwords equivalent). Does not strip
 * legitimate leading punctuation such as ".ALPHA-…" or "(RE) …".
 */
final class DisplayName
{
    public static function clean(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $cleaned = trim($name);

        if ($cleaned === '') {
            return '';
        }

        // Strip one or more leading "-" or ":" tokens followed by whitespace.
        while (preg_match('/^[-:]+[[:space:]]+/u', $cleaned) === 1) {
            $cleaned = preg_replace('/^[-:]+[[:space:]]+/u', '', $cleaned) ?? $cleaned;
            $cleaned = ltrim($cleaned);
        }

        $cleaned = preg_replace('/[[:space:]]+/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        if ($cleaned === '') {
            return '';
        }

        return Str::title($cleaned);
    }
}
