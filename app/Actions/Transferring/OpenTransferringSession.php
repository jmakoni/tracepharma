<?php

namespace App\Actions\Transferring;

use App\Models\Site;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use InvalidArgumentException;

/**
 * Open an intracompany transfer session between two organization-owned sites.
 */
final class OpenTransferringSession
{
    public function handle(
        ?int $fromSiteId = null,
        ?int $toSiteId = null,
        ?int $openedBy = null,
        ?string $notes = null,
    ): TransferringSession {
        if (! TenantFeatures::forTenant(tenant())->supportsTransferring()) {
            throw new DomainException('Transferring is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $settings = TenantSettings::forTenant(tenant());
        $fromSiteId ??= $settings->defaultShipFromSiteId();
        $toSiteId ??= $settings->defaultReceiveSiteId();

        if ($fromSiteId === null || $toSiteId === null) {
            throw new InvalidArgumentException(
                'Both from and to sites are required. Set default ship-from / receive sites in Organization Settings, or pass them explicitly.',
            );
        }

        if ((int) $fromSiteId === (int) $toSiteId) {
            throw new InvalidArgumentException('Cannot transfer to the same site.');
        }

        $fromSite = $this->requireTransferSite((int) $fromSiteId, 'From site');
        $toSite = $this->requireTransferSite((int) $toSiteId, 'To site');

        return TransferringSession::query()->create([
            'from_site_id' => $fromSite->getKey(),
            'to_site_id' => $toSite->getKey(),
            'status' => 'open',
            'confirmed_count' => 0,
            'received_count' => 0,
            'opened_by' => $openedBy,
            'opened_at' => now(),
            'notes' => $notes,
        ]);
    }

    private function requireTransferSite(int $siteId, string $label): Site
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
            throw new DomainException("{$label} must have a 13-digit GLN before transferring.");
        }

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, (int) $site->getKey());
        }

        return $site;
    }
}
