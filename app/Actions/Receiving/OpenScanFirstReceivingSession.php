<?php

namespace App\Actions\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Open an ASN-free scan-first receiving session at an organization-owned site.
 */
final class OpenScanFirstReceivingSession
{
    public function handle(?int $siteId = null, ?int $openedBy = null, ?string $notes = null): ReceivingSession
    {
        if (! TenantFeatures::forTenant(tenant())->supportsReceiving()) {
            throw new DomainException('Receiving is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allows(Permissions::NavReceive)) {
            throw new DomainException('Receiving is not authorized for your job role.');
        }

        $resolvedSiteId = $this->resolveSiteId($siteId);

        return ReceivingSession::query()->create([
            'session_kind' => ReceivingSessionKind::ScanFirst,
            'epcis_document_id' => null,
            'transferring_session_id' => null,
            'matched_epcis_document_id' => null,
            'trading_partner_id' => null,
            'site_id' => $resolvedSiteId,
            'status' => 'open',
            'expected_parent_count' => 0,
            'confirmed_parent_count' => 0,
            'expected_child_count' => 0,
            'confirmed_child_count' => 0,
            'opened_by' => $openedBy,
            'opened_at' => now(),
        ]);
    }

    private function resolveSiteId(?int $explicitSiteId): int
    {
        if ($explicitSiteId !== null) {
            return $this->requireEligibleSite($explicitSiteId, 'Selected receive site');
        }

        $current = CurrentSite::id();
        if ($current !== null) {
            $site = $this->eligibleQuery()->whereKey($current)->first();
            if ($site !== null && filled($site->gln)) {
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
            ->orderBy('id')
            ->first();

        if ($fallback !== null) {
            return (int) $fallback->getKey();
        }

        throw new DomainException(
            'Cannot open scan-first receiving: set a default receive site with a GLN in Organization Settings, or choose a site when starting receive.',
        );
    }

    private function requireEligibleSite(int $siteId, string $label): int
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

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, (int) $site->getKey());
        }

        return (int) $site->getKey();
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
}
