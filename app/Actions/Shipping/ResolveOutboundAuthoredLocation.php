<?php

namespace App\Actions\Shipping;

use App\Models\Site;
use App\Support\Shipping\ResolveShipFromSite;

/**
 * Extension point for outbound EPCIS authoring (shipping / TI generation).
 *
 * There is no GenerateOutbound* pipeline yet; callers that create shipping
 * ObjectEvents should use this action so readPoint/bizLocation GLNs come from
 * the station site or Organization Settings default ship-from site.
 *
 * @return array{
 *     site_id: int,
 *     gln: string,
 *     read_point_gln: string,
 *     biz_location_gln: string,
 *     site: Site
 * }
 */
final class ResolveOutboundAuthoredLocation
{
    public function __construct(
        private readonly ResolveShipFromSite $resolveShipFromSite,
    ) {}

    /**
     * @return array{
     *     site_id: int,
     *     gln: string,
     *     read_point_gln: string,
     *     biz_location_gln: string,
     *     site: Site
     * }
     */
    public function handle(?int $explicitSiteId = null): array
    {
        return $this->resolveShipFromSite->locationGlnsForAuthoring($explicitSiteId);
    }
}
