<?php

namespace App\Support\Fda;

use App\Models\Fda\FdaWddFacility;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Resolve a unique FDA WDD facility for a tenant partner site that was created
 * without the FDA picker (EPCIS ingest, typed address). GLN first, then exact
 * fingerprint, then street/city/state when ZIP is missing on one side.
 */
final class FdaSiteCatalogMatch
{
    public static function wddFacility(Site $site): ?FdaWddFacility
    {
        $site->loadMissing('tradingPartner');

        $orgId = $site->tradingPartner?->fda_organization_id;

        if ($orgId === null) {
            return null;
        }

        $base = FdaWddFacility::query()
            ->where('fda_organization_id', $orgId)
            ->where('is_active', true);

        if (filled($site->gln)) {
            $byGln = self::unique((clone $base)->where('gln', $site->gln)->limit(2)->get());

            if ($byGln !== null) {
                return $byGln;
            }
        }

        $fingerprint = AddressFingerprint::make(
            $site->street_address,
            $site->city,
            $site->state,
            $site->zipcode,
            $site->country_code ?: 'US',
        );

        $byFingerprint = self::unique(
            (clone $base)->where('address_fingerprint', $fingerprint)->limit(2)->get()
        );

        if ($byFingerprint !== null) {
            return $byFingerprint;
        }

        $locality = AddressFingerprint::localityKey(
            $site->street_address,
            $site->city,
            $site->state,
            $site->country_code ?: 'US',
        );

        if ($locality === null) {
            return null;
        }

        $matches = $base->get()->filter(
            function (FdaWddFacility $facility) use ($locality): bool {
                return AddressFingerprint::localityKey(
                    $facility->street_address,
                    $facility->city,
                    $facility->state_province,
                    $facility->country_code ?: 'US',
                ) === $locality;
            }
        );

        return self::unique($matches);
    }

    /**
     * @param  Collection<int, FdaWddFacility>  $hits
     */
    private static function unique(Collection $hits): ?FdaWddFacility
    {
        if ($hits->count() !== 1) {
            return null;
        }

        $facility = $hits->first();

        return $facility instanceof FdaWddFacility ? $facility : null;
    }
}
