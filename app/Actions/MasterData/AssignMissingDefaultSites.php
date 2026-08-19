<?php

namespace App\Actions\MasterData;

use App\Models\Site;
use App\Models\Tenant;
use App\Support\TenantSettings;

/**
 * First organization facility with a GLN becomes the default receive / ship-from
 * site when those settings are still empty. Adding a site from the checklist
 * should complete those items without a second trip through Organization Settings.
 */
final class AssignMissingDefaultSites
{
    public function handle(Site $site): void
    {
        if (! $this->isAssignable($site)) {
            return;
        }

        $this->assign((int) $site->getKey());
    }

    public function healFromExistingSites(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $site = Site::query()
            ->where('is_active', true)
            ->where('is_organization_facility', true)
            ->whereNull('trading_partner_id')
            ->whereNotNull('gln')
            ->where('gln', '!=', '')
            ->orderByDesc('is_headquarters')
            ->orderBy('id')
            ->first();

        if ($site instanceof Site) {
            $this->handle($site);
        }
    }

    private function isAssignable(Site $site): bool
    {
        if (! tenancy()->initialized) {
            return false;
        }

        return (bool) $site->is_active
            && (bool) $site->is_organization_facility
            && $site->trading_partner_id === null
            && filled($site->gln);
    }

    private function assign(int $siteId): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $settings = TenantSettings::forTenant($tenant);
        $changed = false;

        if ($settings->defaultReceiveSiteId() === null) {
            $settings->setDefaultReceiveSiteId($siteId);
            $changed = true;
        }

        if ($settings->defaultShipFromSiteId() === null) {
            $settings->setDefaultShipFromSiteId($siteId);
            $changed = true;
        }

        if ($changed) {
            $tenant->save();
        }
    }
}
