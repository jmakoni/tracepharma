<?php

namespace App\Actions\MasterData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deactivate sites linked to the synthetic "Unassigned" partner.
 *
 * These are not organization facilities — never null trading_partner_id to
 * "promote" them into owned/receive-site dropdowns.
 */
final class PromoteUnassignedSitesToOwned
{
    /**
     * @return array{
     *     sites_deactivated: int,
     *     unassigned_deleted: bool,
     *     defaults_cleared: int,
     * }
     */
    public function handle(): array
    {
        $sitesDeactivated = 0;
        $unassignedDeleted = false;

        if (! Schema::hasTable('sites') || ! Schema::hasTable('trading_partners')) {
            return [
                'sites_deactivated' => 0,
                'unassigned_deleted' => false,
                'defaults_cleared' => 0,
            ];
        }

        $unassignedId = DB::table('trading_partners')->where('name', 'Unassigned')->value('id');

        if ($unassignedId !== null) {
            $payload = [
                'is_active' => false,
            ];

            if (Schema::hasColumn('sites', 'is_organization_facility')) {
                $payload['is_organization_facility'] = false;
            }

            $sitesDeactivated = DB::table('sites')
                ->where('trading_partner_id', $unassignedId)
                ->update($payload);

            $remainingSites = DB::table('sites')
                ->where('trading_partner_id', $unassignedId)
                ->exists();

            $hasProductPivot = Schema::hasTable('trading_partner_product')
                && DB::table('trading_partner_product')
                    ->where('trading_partner_id', $unassignedId)
                    ->exists();

            $hasInboundPivot = Schema::hasTable('inbound_connection_trading_partner')
                && DB::table('inbound_connection_trading_partner')
                    ->where('trading_partner_id', $unassignedId)
                    ->exists();

            if (! $remainingSites && ! $hasProductPivot && ! $hasInboundPivot) {
                DB::table('trading_partners')->where('id', $unassignedId)->delete();
                $unassignedDeleted = true;
            }
        }

        return [
            'sites_deactivated' => $sitesDeactivated,
            'unassigned_deleted' => $unassignedDeleted,
            'defaults_cleared' => $this->clearNonOrganizationDefaultSiteIds(),
        ];
    }

    private function clearNonOrganizationDefaultSiteIds(): int
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        if ($tenant === null) {
            return 0;
        }

        $settings = is_array($tenant->getAttribute('settings'))
            ? $tenant->getAttribute('settings')
            : [];

        $cleared = 0;

        foreach (['default_receive_site_id', 'default_ship_from_site_id'] as $key) {
            $siteId = isset($settings[$key]) ? (int) $settings[$key] : null;

            if ($siteId === null || $siteId < 1) {
                continue;
            }

            $query = DB::table('sites')->where('id', $siteId)->whereNull('trading_partner_id');

            if (Schema::hasColumn('sites', 'is_organization_facility')) {
                $query->where('is_organization_facility', true);
            }

            if (! $query->exists()) {
                unset($settings[$key]);
                $cleared++;
            }
        }

        if ($cleared === 0) {
            return 0;
        }

        $tenant->setAttribute('settings', $settings === [] ? null : $settings);
        $tenant->save();

        return $cleared;
    }
}
