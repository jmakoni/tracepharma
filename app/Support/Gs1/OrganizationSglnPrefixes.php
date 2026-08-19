<?php

namespace App\Support\Gs1;

use App\Models\Site;

/**
 * GS1 Company Prefixes already recorded on organization facilities.
 *
 * A warehouse GLN that sits under one of these prefixes can be encoded without
 * guessing where the company prefix ends.
 */
final class OrganizationSglnPrefixes
{
    /**
     * @return list<string>
     */
    public static function forSite(Site $site): array
    {
        if (! self::isOrganizationFacility($site)) {
            return [];
        }

        $query = Site::query()
            ->ownedByOrganization()
            ->whereNotNull('sgln');

        if ($site->exists) {
            $query->whereKeyNot($site->getKey());
        }

        $prefixes = [];

        foreach ($query->pluck('sgln') as $urn) {
            $parsed = Sgln::fromUrn((string) $urn);
            if ($parsed !== null) {
                $prefixes[$parsed['company_prefix']] = $parsed['company_prefix'];
            }
        }

        return array_values($prefixes);
    }

    public static function isOrganizationFacility(Site $site): bool
    {
        return $site->trading_partner_id === null && (bool) $site->is_organization_facility;
    }
}
