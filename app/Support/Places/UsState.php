<?php

namespace App\Support\Places;

/**
 * Normalizes US state names (full name or already-abbreviated) to their
 * 2-letter uppercase postal code.
 *
 * Covers the 50 states, DC, and the five inhabited territories. Territories
 * license wholesale distributors and dispensers under DSCSA the same way states
 * do, so an ATP license may legitimately name AS, GU, MP, PR or VI.
 */
final class UsState
{
    /**
     * @var array<string, string>
     */
    private const NAMES_TO_CODES = [
        'alabama' => 'AL',
        'alaska' => 'AK',
        'american samoa' => 'AS',
        'arizona' => 'AZ',
        'arkansas' => 'AR',
        'california' => 'CA',
        'colorado' => 'CO',
        'connecticut' => 'CT',
        'delaware' => 'DE',
        'district of columbia' => 'DC',
        'florida' => 'FL',
        'georgia' => 'GA',
        'guam' => 'GU',
        'hawaii' => 'HI',
        'idaho' => 'ID',
        'illinois' => 'IL',
        'indiana' => 'IN',
        'iowa' => 'IA',
        'kansas' => 'KS',
        'kentucky' => 'KY',
        'louisiana' => 'LA',
        'maine' => 'ME',
        'maryland' => 'MD',
        'massachusetts' => 'MA',
        'michigan' => 'MI',
        'minnesota' => 'MN',
        'mississippi' => 'MS',
        'missouri' => 'MO',
        'montana' => 'MT',
        'nebraska' => 'NE',
        'nevada' => 'NV',
        'new hampshire' => 'NH',
        'new jersey' => 'NJ',
        'new mexico' => 'NM',
        'new york' => 'NY',
        'north carolina' => 'NC',
        'north dakota' => 'ND',
        'northern mariana islands' => 'MP',
        'commonwealth of the northern mariana islands' => 'MP',
        'ohio' => 'OH',
        'oklahoma' => 'OK',
        'oregon' => 'OR',
        'pennsylvania' => 'PA',
        'puerto rico' => 'PR',
        'rhode island' => 'RI',
        'south carolina' => 'SC',
        'south dakota' => 'SD',
        'tennessee' => 'TN',
        'texas' => 'TX',
        'utah' => 'UT',
        'vermont' => 'VT',
        'virginia' => 'VA',
        'virgin islands' => 'VI',
        'us virgin islands' => 'VI',
        'u.s. virgin islands' => 'VI',
        'washington' => 'WA',
        'west virginia' => 'WV',
        'wisconsin' => 'WI',
        'wyoming' => 'WY',
    ];

    /**
     * @var array<string, true>
     */
    private const VALID_CODES = [
        'AL' => true, 'AK' => true, 'AS' => true, 'AZ' => true, 'AR' => true,
        'CA' => true, 'CO' => true, 'CT' => true, 'DE' => true, 'DC' => true,
        'FL' => true, 'GA' => true, 'GU' => true, 'HI' => true, 'ID' => true,
        'IL' => true, 'IN' => true, 'IA' => true, 'KS' => true, 'KY' => true,
        'LA' => true, 'ME' => true, 'MD' => true, 'MA' => true, 'MI' => true,
        'MN' => true, 'MS' => true, 'MO' => true, 'MP' => true, 'MT' => true,
        'NE' => true, 'NV' => true, 'NH' => true, 'NJ' => true, 'NM' => true,
        'NY' => true, 'NC' => true, 'ND' => true, 'OH' => true, 'OK' => true,
        'OR' => true, 'PA' => true, 'PR' => true, 'RI' => true, 'SC' => true,
        'SD' => true, 'TN' => true, 'TX' => true, 'UT' => true, 'VT' => true,
        'VA' => true, 'VI' => true, 'WA' => true, 'WV' => true, 'WI' => true,
        'WY' => true,
    ];

    /**
     * Every accepted postal code, sorted — suitable for `Rule::in()`.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = array_keys(self::VALID_CODES);
        sort($codes);

        return $codes;
    }

    /**
     * @return array<string, string>
     */
    public static function selectOptions(): array
    {
        $codes = self::codes();

        return array_combine($codes, $codes);
    }

    /**
     * Normalize a full US state name or 2-letter code to its uppercase
     * 2-letter postal code. Returns null when the input can't be resolved.
     */
    public static function normalize(?string $state): ?string
    {
        $trimmed = trim((string) $state);

        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) === 2 && isset(self::VALID_CODES[strtoupper($trimmed)])) {
            return strtoupper($trimmed);
        }

        $key = strtolower(preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed);

        return self::NAMES_TO_CODES[$key] ?? null;
    }
}
