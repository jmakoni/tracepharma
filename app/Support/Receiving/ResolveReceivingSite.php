<?php

namespace App\Support\Receiving;

use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\User;
use App\Support\Auth\SiteAccess;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolve which tenant Site should own a receiving session's readPoint/bizLocation.
 */
final class ResolveReceivingSite
{
    public function handle(EpcisDocument $document, ?int $explicitSiteId = null): int
    {
        if ($explicitSiteId !== null) {
            $siteId = $this->requireSiteWithGln($explicitSiteId, 'Selected receive site');

            $user = auth()->user();
            if ($user instanceof User) {
                SiteAccess::assertCanAccessSite($user, $siteId);
            }

            return $siteId;
        }

        if ($document->ship_to_site_id !== null) {
            $site = $this->eligibleQuery()
                ->whereKey($document->ship_to_site_id)
                ->first();

            if ($site !== null) {
                return (int) $site->getKey();
            }
        }

        $default = TenantSettings::forTenant(tenant())->defaultReceiveSite();

        if (
            $default !== null
            && $default->is_organization_facility
            && $default->trading_partner_id === null
            && $default->is_active
            && filled($default->gln)
        ) {
            $site = $this->eligibleQuery()
                ->whereKey($default->getKey())
                ->first();

            if ($site !== null) {
                return (int) $site->getKey();
            }
        }

        $fallback = $this->eligibleQuery()
            ->reorder()
            ->orderByDesc('is_headquarters')
            ->orderBy('id')
            ->first();

        if ($fallback !== null) {
            return (int) $fallback->getKey();
        }

        throw new DomainException(
            'Cannot open receiving: set a default receive site with a GLN in Organization Settings, or choose a site when starting receive.',
        );
    }

    /**
     * @return Builder<Site>
     */
    private function eligibleQuery(): Builder
    {
        $user = auth()->user();

        if ($user instanceof User) {
            return EligibleReceiveSites::query($user);
        }

        return EligibleReceiveSites::forOrganization();
    }

    private function requireSiteWithGln(int $siteId, string $label): int
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->ownedByOrganization()
            ->where('is_active', true)
            ->first();

        if ($site === null) {
            throw new DomainException("{$label} was not found, is inactive, or is not an organization-owned site.");
        }

        if (blank($site->gln)) {
            throw new DomainException("{$label} must have a 13-digit GLN before receiving.");
        }

        return (int) $site->getKey();
    }
}
