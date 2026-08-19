<?php

namespace App\Support\Custody;

use App\Models\Site;
use App\Models\Tenant;
use App\Support\Gs1\Sgln;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;

/**
 * Every GLN that counts as "us" — the organization GLN plus the GLNs of all
 * organization facilities ({@see EligibleReceiveSites::forOrganization()}, the
 * query behind organizationOptions()).
 *
 * An EPC last seen at one of these GLNs is in tenant custody.
 */
final class TenantGlnSet
{
    /** @var list<string>|null */
    private ?array $cached = null;

    /** @var list<string>|null */
    private ?array $cachedFacilityGlns = null;

    /**
     * Normalized 13-digit GLNs, organization GLN first.
     *
     * @return list<string>
     */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        /** @var array<string, true> $glns */
        $glns = [];

        $organizationGln = Sgln::normalizeGln(TenantSettings::forTenant($this->tenant())->gln());
        if ($organizationGln !== null) {
            $glns[$organizationGln] = true;
        }

        foreach ($this->siteGlns() as $siteGln) {
            $normalized = Sgln::normalizeGln($siteGln);
            if ($normalized !== null) {
                $glns[$normalized] = true;
            }
        }

        // Keys are cast to int for GLNs with no leading zero; restore strings so
        // strict comparison in contains() holds.
        return $this->cached = array_map(strval(...), array_keys($glns));
    }

    public function contains(?string $gln): bool
    {
        $normalized = Sgln::normalizeGln($gln);

        return $normalized !== null && in_array($normalized, $this->all(), true);
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * Normalized GLNs of organization facilities only, without the organization GLN.
     *
     * Use when the organization GLN itself is the value under test — a partner
     * carrying one of these GLNs can only be a self-partner.
     *
     * @return list<string>
     */
    public function organizationFacilityGlns(): array
    {
        if ($this->cachedFacilityGlns !== null) {
            return $this->cachedFacilityGlns;
        }

        /** @var array<string, true> $glns */
        $glns = [];

        foreach ($this->siteGlns() as $siteGln) {
            $normalized = Sgln::normalizeGln($siteGln);
            if ($normalized !== null) {
                $glns[$normalized] = true;
            }
        }

        return $this->cachedFacilityGlns = array_map(strval(...), array_keys($glns));
    }

    public function containsOrganizationFacilityGln(?string $gln): bool
    {
        $normalized = Sgln::normalizeGln($gln);

        return $normalized !== null && in_array($normalized, $this->organizationFacilityGlns(), true);
    }

    /**
     * @return list<string>
     */
    private function siteGlns(): array
    {
        if (! tenancy()->initialized) {
            return [];
        }

        return EligibleReceiveSites::forOrganization()
            ->get(['id', 'gln'])
            ->map(fn (Site $site): string => (string) $site->gln)
            ->all();
    }

    private function tenant(): ?Tenant
    {
        $tenant = tenancy()->initialized ? tenant() : null;

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
