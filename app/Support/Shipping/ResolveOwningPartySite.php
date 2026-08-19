<?php

namespace App\Support\Shipping;

use App\Models\Site;
use App\Support\Gs1\Sgln;
use Illuminate\Database\Eloquent\Builder;

/**
 * The organization facility that owns the goods on an outbound shipment.
 *
 * The seller on our own shipment is us, so the owning party can only be an
 * organization facility. A partner-owned site carrying the same GLN — mirrored
 * from inbound EPCIS master data — would author a supplier as the party
 * transferring ownership, which reads on the customer's side as goods they
 * bought from someone else.
 *
 * Prefers the facility holding the organization GLN, then headquarters, and
 * falls back to the ship-from dock when the tenant keeps no separate corporate
 * location.
 */
final class ResolveOwningPartySite
{
    public function handle(Site $shipFrom): Site
    {
        $organizationGln = Sgln::normalizeGln((string) (tenant()?->gln ?? ''));

        if ($organizationGln !== null) {
            $byOrganizationGln = $this->organizationFacilities()
                ->where('gln', $organizationGln)
                ->first();

            if ($byOrganizationGln instanceof Site) {
                return $byOrganizationGln;
            }
        }

        $headquarters = $this->organizationFacilities()->first();

        return $headquarters instanceof Site ? $headquarters : $shipFrom;
    }

    /**
     * @return Builder<Site>
     */
    private function organizationFacilities(): Builder
    {
        return Site::query()
            ->ownedByOrganization()
            ->where('is_active', true)
            ->whereNotNull('gln')
            ->where('gln', '!=', '')
            ->orderByDesc('is_headquarters')
            ->orderBy('id');
    }
}
