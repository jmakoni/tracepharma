<?php

declare(strict_types=1);

namespace App\Support\MasterData;

use App\Models\AtpLicense;
use Illuminate\Support\Collection;

/**
 * ATP licenses that should appear on the daily owner expiry digest.
 */
final class AlertableAtpLicenses
{
    /**
     * @return Collection<int, AtpLicense>
     */
    public static function query(): Collection
    {
        $footprint = AtpLicenseRelevance::tenantFootprintKeys();

        if ($footprint === []) {
            return collect();
        }

        return AtpLicense::query()
            ->active()
            ->with([
                'site:id,name,trading_partner_id,is_headquarters,street_address,city,state,zipcode,country_code,fda_wdd_facility_id,fda_establishment_id',
                'site.tradingPartner:id,partner_type,fda_organization_id,name,street_address,city,state,zipcode,country_code',
            ])
            ->where(function ($query): void {
                $query->where(fn ($inner) => AtpLicenseExpiry::expired($inner))
                    ->orWhere(fn ($inner) => AtpLicenseExpiry::expiringSoon($inner))
                    ->orWhere(fn ($inner) => AtpLicenseExpiry::unknownExpiry($inner));
            })
            ->orderBy('license_expiration_date')
            ->get()
            ->filter(function (AtpLicense $license) use ($footprint): bool {
                if (! AtpLicenseRelevance::licenseMatchesFootprint($license, $footprint)) {
                    return false;
                }

                return AtpLicenseRelevance::siteEligibleForExpiryDigest($license->site, requireReceiveProofForManufacturerDc: true);
            })
            ->values();
    }

    /**
     * @return list<string>
     *
     * @deprecated Use AtpLicenseRelevance::tenantFootprintUsStates()
     */
    public static function tenantFootprintStates(): array
    {
        return AtpLicenseRelevance::tenantFootprintUsStates();
    }
}
