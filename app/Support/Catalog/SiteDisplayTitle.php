<?php

namespace App\Support\Catalog;

use App\Models\Site;

/**
 * Site view heading: "Company Name - City" (e.g. "Xttrium Laboratories, Inc. - Glenview").
 */
final class SiteDisplayTitle
{
    public static function make(Site|null $site): string
    {
        if ($site === null) {
            return '';
        }

        $company = DisplayName::clean(
            data_get($site, 'tradingPartner.name')
                ?? $site->name
        );

        $city = filled($site->city)
            ? DisplayName::clean(trim((string) $site->city))
            : null;

        if (filled($company) && filled($city)) {
            return $company.' - '.$city;
        }

        return (string) ($company ?: DisplayName::clean($site->name) ?: '');
    }
}
