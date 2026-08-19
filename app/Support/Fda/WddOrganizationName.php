<?php

namespace App\Support\Fda;

/**
 * Derive the parent organization label from a WDD/3PL Facility_Name that
 * includes a distribution-center / site suffix (e.g. "… - Bangor DC 96").
 */
final class WddOrganizationName
{
    /**
     * Strip trailing " - {site} DC{n}" (and ISC/SLC variants). Returns the
     * original trimmed name when no DC site suffix is present.
     */
    public static function fromFacilityName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            return '';
        }

        $stripped = preg_replace(
            '/\s*-\s*.+?\b(?:ISC|SLC)?\s*DC\s*\d+\s*$/iu',
            '',
            $name,
        );

        $stripped = trim((string) $stripped);

        return $stripped !== '' ? $stripped : $name;
    }
}
