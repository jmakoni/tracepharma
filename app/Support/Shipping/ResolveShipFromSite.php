<?php

namespace App\Support\Shipping;

use App\Models\Site;
use App\Models\User;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\Sgln;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolve which tenant Site should own outbound/authored EPCIS readPoint (and
 * optional bizLocation) GLNs when shipping events are generated.
 *
 * Priority: explicit station site → default_ship_from_site_id → first active
 * site with a GLN → DomainException pointing operators to Organization Settings.
 */
final class ResolveShipFromSite
{
    public function handle(?int $explicitSiteId = null): int
    {
        if ($explicitSiteId !== null) {
            $siteId = $this->requireSiteWithGln($explicitSiteId, 'Selected ship-from site');

            $user = auth()->user();
            if ($user instanceof User) {
                SiteAccess::assertCanAccessSite($user, $siteId);
            } else {
                $this->assertMachineCallerMayUseExplicitSite($siteId);
            }

            return $siteId;
        }

        $default = TenantSettings::forTenant(tenant())->defaultShipFromSite();

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
            'Cannot author outbound EPCIS: set a default ship-from site with a GLN in Organization Settings, or choose a station site when shipping.',
        );
    }

    /**
     * Resolve ship-from site and normalized GLNs for outbound event authoring.
     *
     * @return array{
     *     site_id: int,
     *     gln: string,
     *     read_point_gln: string,
     *     biz_location_gln: string,
     *     site: Site
     * }
     */
    public function locationGlnsForAuthoring(?int $explicitSiteId = null): array
    {
        $siteId = $this->handle($explicitSiteId);
        $site = Site::query()->whereKey($siteId)->firstOrFail();
        $gln = Sgln::normalizeGln($site->gln);

        if ($gln === null) {
            throw new DomainException(
                'Cannot author outbound EPCIS: ship-from site must have a valid 13-digit GLN in Organization Settings / Sites.',
            );
        }

        $this->assertGlnUnderTenantPrefix($gln, $site);

        return [
            'site_id' => $siteId,
            'gln' => $gln,
            'read_point_gln' => $gln,
            'biz_location_gln' => $gln,
            'site' => $site,
        ];
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

    /**
     * Same spirit as TenantSettings::assertValidCompanyPrefix: a ship-from site's GLN must
     * live under the tenant's own GS1 company prefix. Skipped when no prefix is configured
     * yet (nothing to compare against).
     */
    private function assertGlnUnderTenantPrefix(string $gln, Site $site): void
    {
        $prefix = TenantSettings::forTenant(tenant())->companyPrefix();

        if ($prefix === null || $prefix === '') {
            return;
        }

        if (! str_starts_with($gln, $prefix)) {
            throw new DomainException(
                "Ship-from site \"{$site->name}\" GLN is not under the organization GS1 Company Prefix. Fix the site GLN or company prefix in Organization Settings.",
            );
        }
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
            throw new DomainException("{$label} must have a 13-digit GLN before shipping.");
        }

        return (int) $site->getKey();
    }

    /**
     * WMS / machine callers have no user site pivot — bind explicit site_id to the
     * configured default ship-from when one exists, and always require eligibility.
     */
    private function assertMachineCallerMayUseExplicitSite(int $siteId): void
    {
        if (! $this->eligibleQuery()->whereKey($siteId)->exists()) {
            throw new DomainException(
                'Selected ship-from site is not an allowed organization facility with a GLN.',
            );
        }

        $defaultId = TenantSettings::forTenant(tenant())->defaultShipFromSiteId();

        if ($defaultId === null) {
            throw new DomainException(
                'Machine ship-from requests must omit site_id until a default ship-from site is configured in Organization Settings.',
            );
        }

        if ((int) $defaultId !== $siteId) {
            throw new DomainException(
                'Machine ship-from site must match the configured default ship-from site.',
            );
        }
    }
}
