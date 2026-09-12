<?php

declare(strict_types=1);

namespace App\Support\Custody;

/**
 * Floor workstations resolve principal from the active site default
 * (same source as soft principal filters / receive-ship open).
 */
trait ResolvesFloorSitePrincipal
{
    protected function floorPrincipalId(?int $siteId): ?int
    {
        return PrincipalCustody::forTenant()->activePrincipalIdForSite($siteId);
    }
}
