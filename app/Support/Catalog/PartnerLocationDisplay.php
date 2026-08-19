<?php

namespace App\Support\Catalog;

use App\Support\Geo\TimezoneFromAddress;
use Illuminate\Database\Eloquent\Model;

final class PartnerLocationDisplay
{
    /**
     * @var array<string, string>
     */
    private const TIMEZONE_LABELS = [
        'America/New_York' => 'Eastern Time',
        'America/Detroit' => 'Eastern Time',
        'America/Indiana/Indianapolis' => 'Eastern Time',
        'America/Kentucky/Louisville' => 'Eastern Time',
        'America/Chicago' => 'Central Time',
        'America/Menominee' => 'Central Time',
        'America/Denver' => 'Mountain Time',
        'America/Boise' => 'Mountain Time',
        'America/Phoenix' => 'Mountain Time',
        'America/Los_Angeles' => 'Pacific Time',
        'America/Anchorage' => 'Alaska Time',
        'Pacific/Honolulu' => 'Hawaii Time',
        'America/Puerto_Rico' => 'Atlantic Time',
    ];

    /**
     * @return list<string>
     */
    public static function addressLines(Model $record): array
    {
        $lines = [];

        if (filled($record->getAttribute('street_address'))) {
            $lines[] = DisplayName::clean(trim((string) $record->getAttribute('street_address')));
        }
        if (filled($record->getAttribute('street_address_2'))) {
            $lines[] = DisplayName::clean(trim((string) $record->getAttribute('street_address_2')));
        }

        $city = filled($record->getAttribute('city'))
            ? DisplayName::clean(trim((string) $record->getAttribute('city')))
            : null;
        $state = filled($record->getAttribute('state'))
            ? strtoupper(trim((string) $record->getAttribute('state')))
            : null;
        $zip = filled($record->getAttribute('zipcode'))
            ? trim((string) $record->getAttribute('zipcode'))
            : null;

        $cityStateZip = '';
        if (filled($city) || filled($state)) {
            $cityStateZip = implode(', ', array_filter([$city, $state], static fn (?string $v): bool => filled($v)));
        }
        if (filled($zip)) {
            $cityStateZip = trim($cityStateZip.' '.$zip);
        }
        if ($cityStateZip !== '') {
            $lines[] = $cityStateZip;
        }

        $country = self::countryName($record->getAttribute('country_code'));
        if (filled($country)) {
            $lines[] = $country;
        }

        return array_values(array_filter($lines, static fn (?string $line): bool => filled($line)));
    }

    public static function addressLine(Model $record): ?string
    {
        $lines = self::addressLines($record);

        return $lines === [] ? null : implode(' ', $lines);
    }

    public static function resolveTimezone(Model $record): ?string
    {
        if (filled($record->getAttribute('timezone'))) {
            return trim((string) $record->getAttribute('timezone'));
        }

        return TimezoneFromAddress::resolve(
            $record->getAttribute('country_code'),
            $record->getAttribute('state'),
            $record->getAttribute('city'),
        );
    }

    public static function timezoneLine(?string $iana): ?string
    {
        if (blank($iana)) {
            return null;
        }

        $iana = trim($iana);
        $friendly = self::friendlyLabel($iana);

        return filled($friendly)
            ? "Timezone: {$iana} ({$friendly})"
            : "Timezone: {$iana}";
    }

    public static function friendlyLabel(string $iana): ?string
    {
        if (isset(self::TIMEZONE_LABELS[$iana])) {
            return self::TIMEZONE_LABELS[$iana];
        }

        if (class_exists(\IntlTimeZone::class)) {
            $tz = \IntlTimeZone::createTimeZone($iana);
            if ($tz !== null && $tz->getID() !== 'Etc/Unknown') {
                $name = $tz->getDisplayName(false, \IntlTimeZone::DISPLAY_GENERIC_LOCATION, 'en_US');
                if (filled($name) && $name !== $iana) {
                    return $name;
                }
            }
        }

        return null;
    }

    public static function countryName(mixed $countryCode): ?string
    {
        $code = strtoupper(trim((string) ($countryCode ?? '')));

        return match ($code) {
            'US', 'USA' => 'United States',
            'CA', 'CAN' => 'Canada',
            'MX', 'MEX' => 'Mexico',
            '' => null,
            default => $code,
        };
    }
}
