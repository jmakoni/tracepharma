<?php

declare(strict_types=1);

namespace App\Support\MasterData;

use App\Models\Epcis\EpcisDocument;

/**
 * True when the tenant has already ingested inbound EPCIS shipped from this partner site.
 */
final class PartnerSiteHasInboundShipFrom
{
    /**
     * @param  list<string>  $statuses
     */
    public static function exists(int $siteId, array $statuses = ['parsed', 'validated']): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        return EpcisDocument::query()
            ->where('direction', 'inbound')
            ->where('ship_from_site_id', $siteId)
            ->whereIn('status', $statuses)
            ->exists();
    }
}
