<?php

namespace App\Support\Fda;

/**
 * Canonical legal-entity name for FDA organization matching.
 *
 * PartnerSlug uses {@see self::prepare()} so catalog slugs keep abbreviated
 * suffixes (inc/corp/ltd) and stay compatible with existing rows.
 */
final class CompanyNameNormalizer
{
    /**
     * Trailing legal-entity suffixes, longest first.
     *
     * @var list<string>
     */
    private const SUFFIXES = [
        'INCORPORATED',
        'CORPORATION',
        'COMPANY',
        'LIMITED',
        'PLLC',
        'LLC',
        'LLP',
        'PLC',
        'INC',
        'CORP',
        'LTD',
        'LP',
        'PC',
        'CO',
    ];

    /**
     * @var list<string>
     */
    private const TRAILING_STATE_NAMES = [
        'DISTRICT OF COLUMBIA',
        'NEW HAMPSHIRE',
        'NEW JERSEY',
        'NEW MEXICO',
        'NEW YORK',
        'NORTH CAROLINA',
        'NORTH DAKOTA',
        'RHODE ISLAND',
        'SOUTH CAROLINA',
        'SOUTH DAKOTA',
        'WEST VIRGINIA',
        'ALABAMA',
        'ALASKA',
        'ARIZONA',
        'ARKANSAS',
        'CALIFORNIA',
        'COLORADO',
        'CONNECTICUT',
        'DELAWARE',
        'FLORIDA',
        'GEORGIA',
        'HAWAII',
        'IDAHO',
        'ILLINOIS',
        'INDIANA',
        'IOWA',
        'KANSAS',
        'KENTUCKY',
        'LOUISIANA',
        'MAINE',
        'MARYLAND',
        'MASSACHUSETTS',
        'MICHIGAN',
        'MINNESOTA',
        'MISSISSIPPI',
        'MISSOURI',
        'MONTANA',
        'NEBRASKA',
        'NEVADA',
        'OHIO',
        'OKLAHOMA',
        'OREGON',
        'PENNSYLVANIA',
        'TENNESSEE',
        'TEXAS',
        'UTAH',
        'VERMONT',
        'VIRGINIA',
        'WASHINGTON',
        'WISCONSIN',
        'WYOMING',
    ];

    public static function canonical(string $name): string
    {
        $value = self::prepare($name);
        $value = self::normalizeCountryTokens($value);
        $value = self::normalizeCompoundTokens($value);
        $value = self::stripTrailingLocations($value);
        // Optional phrases may sit before legal suffixes ("… Factory Co Ltd").
        $value = self::stripTrailingOptionalPhrases($value);
        $value = self::stripSuffixes($value);
        $value = self::stripTrailingOptionalPhrases($value);
        $value = self::stripSuffixes($value);

        return $value;
    }

    /**
     * Uppercase, strip dba/fka/division-of wrappers, drop punctuation.
     * Leaves legal suffixes in place for slug compatibility.
     */
    public static function prepare(string $name): string
    {
        $value = trim($name);

        if ($value === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                $value = $normalized;
            }
        }

        $value = self::splitTradeName($value);
        $value = strtoupper($value);
        $value = preg_replace('/^THE\s+/', '', $value) ?? $value;
        $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? $value;
        // Glued "CoLtd" / "COLTD" (common in OpenFDA Chinese labelers) → "CO LTD".
        $value = preg_replace('/\bCOLTD\b/', 'CO LTD', $value) ?? $value;
        $value = preg_replace('/\bCOLIMITED\b/', 'CO LIMITED', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Align hyphenated vs closed compounds seen across FDA labeler spellings.
     */
    private static function normalizeCompoundTokens(string $value): string
    {
        $value = preg_replace('/\bBIO\s+PHARMACEUTICALS?\b/', 'BIOPHARMACEUTICAL', $value) ?? $value;
        $value = preg_replace('/\bBIO\s+PHARMA\b/', 'BIOPHARMA', $value) ?? $value;
        $value = preg_replace('/\bBIO\s+PHARM\b/', 'BIOPHARM', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * Optional trailing site/division labels that often differ across FDA spellings
     * of the same Chinese legal entity (Factory, Sci-Tech, …).
     *
     * @var list<string>
     */
    private const TRAILING_OPTIONAL_PHRASES = [
        'SCI TECH',
        'FACTORY',
    ];

    private static function stripTrailingOptionalPhrases(string $value): string
    {
        $changed = true;

        while ($changed && $value !== '') {
            $changed = false;

            foreach (self::TRAILING_OPTIONAL_PHRASES as $phrase) {
                $pattern = '/\b'.preg_quote($phrase, '/').'$/';
                $stripped = trim(preg_replace($pattern, '', $value) ?? $value);

                if ($stripped !== '' && $stripped !== $value) {
                    $value = $stripped;
                    $changed = true;
                    break;
                }
            }
        }

        return $value;
    }

    private static function splitTradeName(string $value): string
    {
        if (preg_match('/^(.*?)\s+(?:d\/b\/a|dba|f\/k\/a|fka)\s+/i', $value, $matches) === 1) {
            $before = trim($matches[1]);
            if ($before !== '') {
                $value = $before;
            }
        }

        if (preg_match('/\ba\s+division\s+of\s+(.+)$/i', $value, $matches) === 1) {
            $after = trim($matches[1]);
            if ($after !== '') {
                $value = $after;
            }
        }

        return $value;
    }

    private static function normalizeCountryTokens(string $value): string
    {
        $value = preg_replace('/\bU\s*S\s*A\b/', 'US', $value) ?? $value;
        $value = preg_replace('/\bU\s*S\b/', 'US', $value) ?? $value;
        $value = preg_replace('/\bUNITED STATES(?: OF AMERICA)?\b/', 'US', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function stripTrailingLocations(string $value): string
    {
        $value = preg_replace('/\bUS$/', '', $value) ?? $value;
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        foreach (self::TRAILING_STATE_NAMES as $stateName) {
            $pattern = '/\b'.preg_quote($stateName, '/').'$/';
            $stripped = trim(preg_replace($pattern, '', $value) ?? $value);
            if ($stripped !== $value) {
                return trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
            }
        }

        return $value;
    }

    private static function stripSuffixes(string $value): string
    {
        $changed = true;

        while ($changed && $value !== '') {
            $changed = false;

            foreach (self::SUFFIXES as $suffix) {
                $pattern = '/\b'.preg_quote($suffix, '/').'$/';
                $stripped = trim(preg_replace($pattern, '', $value) ?? $value);

                if ($stripped !== $value) {
                    $value = $stripped;
                    $changed = true;
                    break;
                }
            }
        }

        return $value;
    }
}
