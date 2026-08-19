<?php

namespace App\Support\Fda;

use App\Support\Places\UsState;

/**
 * Splits the concatenated DECRS ADDRESS field into structured parts.
 *
 * US shape: `{street}, {city}, {StateName} ({ST}) {ZIP}, United States (USA)`
 * All rows end with `(ISO3)`.
 *
 * @phpstan-type ParsedAddress array{
 *     street_address: ?string,
 *     city: ?string,
 *     state_province: ?string,
 *     postal_code: ?string,
 *     country_code: ?string,
 *     full_address: string
 * }
 */
final class DecrsAddressParser
{
    /**
     * ISO 3166-1 alpha-3 → alpha-2 for countries seen in DECRS.
     *
     * @var array<string, string>
     */
    private const ISO3_TO_ISO2 = [
        'USA' => 'US', 'CHN' => 'CN', 'IND' => 'IN', 'DEU' => 'DE', 'CAN' => 'CA',
        'FRA' => 'FR', 'ITA' => 'IT', 'GBR' => 'GB', 'KOR' => 'KR', 'JPN' => 'JP',
        'ESP' => 'ES', 'CHE' => 'CH', 'MEX' => 'MX', 'IRL' => 'IE', 'TWN' => 'TW',
        'AUS' => 'AU', 'BRA' => 'BR', 'NLD' => 'NL', 'BEL' => 'BE', 'AUT' => 'AT',
        'SWE' => 'SE', 'POL' => 'PL', 'DNK' => 'DK', 'FIN' => 'FI', 'NOR' => 'NO',
        'PRT' => 'PT', 'GRC' => 'GR', 'CZE' => 'CZ', 'HUN' => 'HU', 'ROU' => 'RO',
        'ISR' => 'IL', 'SGP' => 'SG', 'THA' => 'TH', 'MYS' => 'MY', 'IDN' => 'ID',
        'PHL' => 'PH', 'VNM' => 'VN', 'ARG' => 'AR', 'CHL' => 'CL', 'COL' => 'CO',
        'ZAF' => 'ZA', 'EGY' => 'EG', 'TUR' => 'TR', 'RUS' => 'RU', 'UKR' => 'UA',
        'NZL' => 'NZ', 'HKG' => 'HK', 'PRI' => 'PR', 'ISL' => 'IS', 'LUX' => 'LU',
        'SVK' => 'SK', 'SVN' => 'SI', 'HRV' => 'HR', 'BGR' => 'BG', 'LTU' => 'LT',
        'LVA' => 'LV', 'EST' => 'EE', 'CYP' => 'CY', 'MLT' => 'MT', 'JOR' => 'JO',
        'SAU' => 'SA', 'ARE' => 'AE', 'PAK' => 'PK', 'BGD' => 'BD', 'LKA' => 'LK',
        'KHM' => 'KH', 'MMR' => 'MM', 'MAC' => 'MO', 'CRI' => 'CR', 'PAN' => 'PA',
        'PER' => 'PE', 'URY' => 'UY', 'ECU' => 'EC', 'DOM' => 'DO', 'GTM' => 'GT',
        'HND' => 'HN', 'SLV' => 'SV', 'NIC' => 'NI', 'JAM' => 'JM', 'TTO' => 'TT',
        'MAR' => 'MA', 'TUN' => 'TN', 'KEN' => 'KE', 'NGA' => 'NG', 'GHA' => 'GH',
        'SRB' => 'RS', 'BIH' => 'BA', 'MKD' => 'MK', 'ALB' => 'AL', 'GEO' => 'GE',
        'ARM' => 'AM', 'AZE' => 'AZ', 'KAZ' => 'KZ', 'UZB' => 'UZ', 'BLR' => 'BY',
        'MDA' => 'MD', 'LIE' => 'LI', 'MCO' => 'MC', 'AND' => 'AD', 'SMR' => 'SM',
        'VAT' => 'VA', 'QAT' => 'QA', 'KWT' => 'KW', 'BHR' => 'BH', 'OMN' => 'OM',
        'LBN' => 'LB', 'IRQ' => 'IQ', 'IRN' => 'IR', 'AFG' => 'AF', 'NPL' => 'NP',
        'MNG' => 'MN', 'PRK' => 'KP', 'BRN' => 'BN', 'LAO' => 'LA', 'PNG' => 'PG',
        'FJI' => 'FJ', 'CUB' => 'CU', 'HTI' => 'HT', 'BOL' => 'BO', 'PRY' => 'PY',
        'VEN' => 'VE', 'GUY' => 'GY', 'SUR' => 'SR', 'BLZ' => 'BZ', 'BHS' => 'BS',
        'BRB' => 'BB', 'CYP' => 'CY', 'ABW' => 'AW', 'RWA' => 'RW', 'COG' => 'CG',
        'CYM' => 'KY',
    ];

    /**
     * @return ParsedAddress
     */
    public static function parse(string $address): array
    {
        $full = trim($address);

        $countryCode = null;
        if (preg_match('/\(([A-Z]{3})\)\s*$/', $full, $isoMatch) === 1) {
            $countryCode = self::ISO3_TO_ISO2[$isoMatch[1]] ?? null;
        }

        if (preg_match(
            '/^(.*?),\s*([^,]+),\s*[A-Za-z .]+?\s*\(([A-Z]{2})\)\s*(\d{5}(?:-\d{4})?),\s*United States\s*\(USA\)\s*$/i',
            $full,
            $usMatch
        ) === 1) {
            $state = UsState::normalize($usMatch[3]) ?? strtoupper($usMatch[3]);

            return [
                'street_address' => self::nullable(trim($usMatch[1])),
                'city' => self::nullable(trim($usMatch[2])),
                'state_province' => $state,
                'postal_code' => self::nullable(trim($usMatch[4])),
                'country_code' => 'US',
                'full_address' => $full,
            ];
        }

        $withoutCountry = preg_replace('/,\s*[^,]+?\s*\([A-Z]{3}\)\s*$/', '', $full) ?? $full;
        $withoutCountry = trim($withoutCountry);

        $street = $withoutCountry;
        $city = null;
        $stateProvince = null;
        $postalCode = null;

        if (preg_match('/^(.*),\s*([^,]+),\s*([^,]+)$/', $withoutCountry, $parts) === 1) {
            $street = trim($parts[1]);
            $city = self::nullable(trim($parts[2]));
            [$stateProvince, $postalCode] = self::splitRegionAndPostal(trim($parts[3]));
        }

        return [
            'street_address' => self::nullable($street),
            'city' => $city,
            'state_province' => $stateProvince,
            'postal_code' => $postalCode,
            'country_code' => $countryCode,
            'full_address' => $full,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string} [state_province, postal_code]
     */
    private static function splitRegionAndPostal(string $tail): array
    {
        $tail = trim($tail);

        if ($tail === '') {
            return [null, null];
        }

        $tokens = preg_split('/\s+/', $tail) ?: [];
        $last = (string) ($tokens[count($tokens) - 1] ?? '');
        $prev = (string) ($tokens[count($tokens) - 2] ?? '');

        if ($prev !== ''
            && preg_match('/^[A-Z]{1,2}\d[A-Z\d]?$/i', $prev) === 1
            && preg_match('/^\d[A-Z]{2}$/i', $last) === 1) {
            $region = trim(implode(' ', array_slice($tokens, 0, -2)));

            return [self::nullable($region), strtoupper($prev.' '.$last)];
        }

        if ($prev !== ''
            && preg_match('/^[A-Z]\d[A-Z]$/i', $prev) === 1
            && preg_match('/^\d[A-Z]\d$/i', $last) === 1) {
            $region = trim(implode(' ', array_slice($tokens, 0, -2)));

            return [self::nullable($region), strtoupper($prev.' '.$last)];
        }

        if (preg_match('/\d/', $last) === 1 && strlen($last) <= 16) {
            $region = trim(implode(' ', array_slice($tokens, 0, -1)));

            return [self::nullable($region), strtoupper($last)];
        }

        return [self::nullable($tail), null];
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
