<?php

namespace App\Support;

use App\Support\Fda\CompanyNameNormalizer;
use Illuminate\Support\Str;

/**
 * Stable manufacturer identity for catalog trading partners.
 *
 * Legal-entity suffixes (e.g. "Corporation" vs "Corp", "Incorporated" vs
 * "Inc", "L.L.C." vs "LLC", trailing "Limited"/"Company" variants) are
 * canonicalized before slugging so that name variants for the same legal
 * entity resolve to a single slug.
 */
final class PartnerSlug
{
    /**
     * Whole-word replacements safe to apply anywhere in the name.
     *
     * @var array<string, string>
     */
    private const MID_NAME_REPLACEMENTS = [
        'corporation' => 'corp',
        'incorporated' => 'inc',
    ];

    /**
     * Trailing-only replacements, keyed by regex fragment (without anchors) => canonical form.
     *
     * @var array<string, string>
     */
    private const TRAILING_REPLACEMENTS = [
        'limited|ltd\.?' => 'ltd',
        'company|co\.?' => 'co',
    ];

    public static function from(string $name): string
    {
        $prepared = CompanyNameNormalizer::prepare($name);
        $normalized = self::normalizeLegalSuffixes($prepared === '' ? trim($name) : $prepared);
        $slug = Str::slug($normalized);

        if ($slug === '') {
            return 'partner-'.substr(hash('sha256', trim($name)), 0, 8);
        }

        return $slug;
    }

    private static function normalizeLegalSuffixes(string $name): string
    {
        foreach (self::MID_NAME_REPLACEMENTS as $pattern => $replacement) {
            $name = preg_replace('/\b'.$pattern.'\b/i', $replacement, $name) ?? $name;
        }

        $name = preg_replace('/\bl\.?\s*l\.?\s*c\.?\b/i', 'llc', $name) ?? $name;

        foreach (self::TRAILING_REPLACEMENTS as $pattern => $replacement) {
            $name = preg_replace('/\b(?:'.$pattern.')\s*$/i', $replacement, $name) ?? $name;
        }

        return $name;
    }
}
