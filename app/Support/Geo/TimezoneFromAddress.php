<?php

namespace App\Support\Geo;

use App\Support\Places\UsState;

/**
 * Resolves an IANA timezone from country + region address fields.
 *
 * Prefer a stored Places/API timezone when available. This is a fallback for
 * address-only records (imports) where lat/lng were never geocoded.
 */
final class TimezoneFromAddress
{
    /**
     * Majority / canonical IANA zone by US state/territory code.
     *
     * @var array<string, string>
     */
    private const US_STATE_TIMEZONES = [
        'AL' => 'America/Chicago',
        'AK' => 'America/Anchorage',
        'AZ' => 'America/Phoenix',
        'AR' => 'America/Chicago',
        'CA' => 'America/Los_Angeles',
        'CO' => 'America/Denver',
        'CT' => 'America/New_York',
        'DE' => 'America/New_York',
        'DC' => 'America/New_York',
        'FL' => 'America/New_York',
        'GA' => 'America/New_York',
        'HI' => 'Pacific/Honolulu',
        'ID' => 'America/Boise',
        'IL' => 'America/Chicago',
        'IN' => 'America/Indiana/Indianapolis',
        'IA' => 'America/Chicago',
        'KS' => 'America/Chicago',
        'KY' => 'America/New_York',
        'LA' => 'America/Chicago',
        'ME' => 'America/New_York',
        'MD' => 'America/New_York',
        'MA' => 'America/New_York',
        'MI' => 'America/Detroit',
        'MN' => 'America/Chicago',
        'MS' => 'America/Chicago',
        'MO' => 'America/Chicago',
        'MT' => 'America/Denver',
        'NE' => 'America/Chicago',
        'NV' => 'America/Los_Angeles',
        'NH' => 'America/New_York',
        'NJ' => 'America/New_York',
        'NM' => 'America/Denver',
        'NY' => 'America/New_York',
        'NC' => 'America/New_York',
        'ND' => 'America/Chicago',
        'OH' => 'America/New_York',
        'OK' => 'America/Chicago',
        'OR' => 'America/Los_Angeles',
        'PA' => 'America/New_York',
        'PR' => 'America/Puerto_Rico',
        'RI' => 'America/New_York',
        'SC' => 'America/New_York',
        'SD' => 'America/Chicago',
        'TN' => 'America/Chicago',
        'TX' => 'America/Chicago',
        'UT' => 'America/Denver',
        'VT' => 'America/New_York',
        'VA' => 'America/New_York',
        'WA' => 'America/Los_Angeles',
        'WV' => 'America/New_York',
        'WI' => 'America/Chicago',
        'WY' => 'America/Denver',
    ];

    /**
     * Eastern-time Tennessee cities (Central is the state default).
     *
     * @var array<string, true>
     */
    private const TN_EASTERN_CITIES = [
        'bristol' => true,
        'chattanooga' => true,
        'cleveland' => true,
        'johnson city' => true,
        'kingsport' => true,
        'knoxville' => true,
        'maryville' => true,
        'morristown' => true,
        'oak ridge' => true,
    ];

    /**
     * @var array<string, string>
     */
    private const CA_PROVINCE_TIMEZONES = [
        'AB' => 'America/Edmonton',
        'BC' => 'America/Vancouver',
        'MB' => 'America/Winnipeg',
        'NB' => 'America/Moncton',
        'NL' => 'America/St_Johns',
        'NS' => 'America/Halifax',
        'NT' => 'America/Yellowknife',
        'NU' => 'America/Iqaluit',
        'ON' => 'America/Toronto',
        'PE' => 'America/Halifax',
        'QC' => 'America/Toronto',
        'SK' => 'America/Regina',
        'YT' => 'America/Whitehorse',
    ];

    public static function resolve(?string $countryCode, ?string $state, ?string $city = null): ?string
    {
        $country = strtoupper(trim((string) $countryCode));

        if (in_array($country, ['US', 'USA'], true)) {
            return self::resolveUs($state, $city);
        }

        if (in_array($country, ['CA', 'CAN'], true)) {
            return self::resolveCanada($state);
        }

        return null;
    }

    private static function resolveUs(?string $state, ?string $city): ?string
    {
        $code = UsState::normalize($state);

        if ($code === null) {
            return null;
        }

        if ($code === 'TN' && self::isEasternTennesseeCity($city)) {
            return 'America/New_York';
        }

        return self::US_STATE_TIMEZONES[$code] ?? null;
    }

    private static function resolveCanada(?string $province): ?string
    {
        $code = strtoupper(trim((string) $province));

        if (strlen($code) !== 2) {
            return null;
        }

        return self::CA_PROVINCE_TIMEZONES[$code] ?? null;
    }

    private static function isEasternTennesseeCity(?string $city): bool
    {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', (string) $city) ?? ''));

        return $key !== '' && isset(self::TN_EASTERN_CITIES[$key]);
    }
}
