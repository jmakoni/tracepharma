<?php

namespace App\Actions\MasterData;

use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Custody\TenantGlnSet;
use App\Support\Gs1\Sgln;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Schema;

/**
 * Heal trading partners that are actually the organization itself.
 *
 * EPCIS auto-create used to mirror our own destination party into the tenant
 * partner list. Those self-partners break SSCC identity checks and collide with
 * organization facilities on the UNIQUE sites.gln index.
 *
 * Deactivate rather than delete: partners are referenced by documents, products
 * and licenses. A "[SELF]" name prefix makes the leftover row obvious in the UI.
 */
final class DeactivateSelfTradingPartners
{
    public const NAME_PREFIX = '[SELF] ';

    /**
     * @return array{partners_deactivated: int, partners_renamed: int, sites_promoted: int}
     */
    public function handle(): array
    {
        $stats = [
            'partners_deactivated' => 0,
            'partners_renamed' => 0,
            'sites_promoted' => 0,
        ];

        if (! Schema::hasTable('trading_partners')) {
            return $stats;
        }

        if ((new TenantGlnSet)->isEmpty()) {
            return $stats;
        }

        $partners = TradingPartner::query()
            ->whereNotNull('gln')
            ->where('gln', '!=', '')
            ->get();

        foreach ($partners as $partner) {
            // Rebuilt per partner: promoting a site hands another GLN back to the
            // organization, and a cached set would not know about it.
            if (! (new TenantGlnSet)->contains($partner->gln)) {
                continue;
            }

            $stats['sites_promoted'] += $this->promoteOrganizationSites($partner);

            $updates = [];

            if ((bool) $partner->is_active) {
                $updates['is_active'] = false;
                $stats['partners_deactivated']++;
            }

            $name = (string) $partner->name;
            if (! str_starts_with($name, self::NAME_PREFIX)) {
                $updates['name'] = self::NAME_PREFIX.$name;
                $stats['partners_renamed']++;
            }

            if ($updates !== []) {
                $partner->forceFill($updates)->save();
            }
        }

        return $stats;
    }

    /**
     * Hand the organization GLN back to the organization so an org facility can
     * own it again; the UNIQUE sites.gln index allows only one holder.
     */
    private function promoteOrganizationSites(TradingPartner $partner): int
    {
        if (! Schema::hasTable('sites')) {
            return 0;
        }

        $tenant = tenancy()->initialized ? tenant() : null;
        $organizationGln = $tenant instanceof Tenant
            ? Sgln::normalizeGln(TenantSettings::forTenant($tenant)->gln())
            : null;

        if ($organizationGln === null) {
            return 0;
        }

        $payload = [
            'trading_partner_id' => null,
            'is_active' => true,
        ];

        if (Schema::hasColumn('sites', 'is_organization_facility')) {
            $payload['is_organization_facility'] = true;
        }

        $promoted = 0;

        $sites = Site::query()
            ->where('trading_partner_id', $partner->getKey())
            ->whereNotNull('gln')
            ->get();

        foreach ($sites as $site) {
            if (Sgln::normalizeGln($site->gln) !== $organizationGln) {
                continue;
            }

            $site->forceFill($payload)->save();

            $promoted++;
        }

        return $promoted;
    }
}
