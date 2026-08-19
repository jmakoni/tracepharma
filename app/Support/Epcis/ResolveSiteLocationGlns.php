<?php

namespace App\Support\Epcis;

use App\Models\Site;
use App\Support\Gs1\OrganizationSglnPrefixes;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use App\Support\Shipping\ResolveShipFromSite;
use App\Support\TenantSettings;
use DomainException;

/**
 * readPoint/bizLocation identity for a site a record already names.
 *
 * ResolveShipFromSite answers a different question — which site may *this operator*
 * author from — so it asserts SiteAccess and shipping eligibility. Neither applies
 * once the site is fixed on a session: the destination operator closing a transfer
 * receive has no pivot row for the origin warehouse, and a site retired after the
 * shipment left still has to be named in the document that describes it. This
 * resolves identity only, and leaves authorization to the entry point that acts.
 *
 * @see ResolveShipFromSite for operator-scoped selection
 */
final class ResolveSiteLocationGlns
{
    /**
     * @return array{site_id: int, gln: string, sgln_urn: string, site: Site}
     *
     * @throws DomainException when the site, its GLN, or its SGLN cannot be resolved
     */
    public function handle(int $siteId, string $label = 'Site'): array
    {
        $site = $this->site($siteId);

        if ($site === null) {
            throw new DomainException("{$label} (#{$siteId}) was not found or is not an organization-owned site.");
        }

        $gln = Sgln::normalizeGln($site->gln);

        if ($gln === null) {
            throw new DomainException("{$label} \"{$site->name}\" must have a valid 13-digit GLN before EPCIS can be authored for it.");
        }

        $orgPrefix = TenantSettings::forTenant(tenant())->companyPrefix();
        // Sibling facility prefixes supplement a declared organization prefix.
        // With no prefix and no SGLN on this site, fail closed — do not invent
        // ship-from identity from another warehouse's recorded split.
        $additionalPrefixes = $orgPrefix !== null
            ? OrganizationSglnPrefixes::forSite($site)
            : [];
        $sglnUrn = $this->sglnUrn($gln, [$site->getAttribute('sgln')], $additionalPrefixes);

        if ($sglnUrn === null) {
            $prefixNote = $orgPrefix !== null
                ? " GLN {$gln} is not issued under the organization GS1 Company Prefix {$orgPrefix}."
                : ' The organization GS1 Company Prefix is not set.';

            throw new DomainException(
                "No SGLN on record for {$label} \"{$site->name}\" (GLN {$gln}).{$prefixNote} "
                .'Record the site SGLN as urn:epc:id:sgln:companyPrefix.locationReference.extension, '
                .'or use a GLN issued under the organization prefix.',
            );
        }

        return [
            'site_id' => (int) $site->getKey(),
            'gln' => $gln,
            'sgln_urn' => $sglnUrn,
            'site' => $site,
        ];
    }

    /**
     * SGLN for a GLN already authored on a record, rather than for whatever the site
     * says today. Candidates on record win over the company-prefix encoding, so a URN
     * we already transmitted for this location is reused instead of re-derived.
     *
     * @param  list<mixed>  $candidates  SGLN URNs on record for this location
     */
    public function sglnUrnForRecordedGln(string $gln, ?int $siteId = null, array $candidates = []): ?string
    {
        $site = $siteId !== null ? $this->site($siteId) : null;

        return $this->sglnUrn(
            $gln,
            [...$candidates, $site?->getAttribute('sgln')],
            $site !== null ? OrganizationSglnPrefixes::forSite($site) : [],
        );
    }

    /**
     * @param  list<mixed>  $candidates
     * @param  list<string>  $additionalPrefixes
     */
    private function sglnUrn(string $gln, array $candidates, array $additionalPrefixes = []): ?string
    {
        return SglnResolution::resolve(
            $gln,
            $candidates,
            TenantSettings::forTenant(tenant())->companyPrefix(),
            $additionalPrefixes,
        );
    }

    private function site(int $siteId): ?Site
    {
        return Site::query()
            ->whereKey($siteId)
            ->ownedByOrganization()
            ->first();
    }
}
