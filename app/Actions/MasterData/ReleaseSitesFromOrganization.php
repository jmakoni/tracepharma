<?php

namespace App\Actions\MasterData;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop organization-side references to sites that are no longer organization
 * facilities: user site assignments and the tenant default receive / ship-from
 * settings.
 *
 * A site that gains a trading partner belongs to that partner, so leaving a
 * site_user row behind would keep granting floor access to a partner location,
 * and a default pointing at it would keep resolving receive / ship-from there.
 */
final class ReleaseSitesFromOrganization
{
    /**
     * @param  list<int>  $siteIds
     * @return array{pivots_deleted: int, defaults_cleared: int}
     */
    public function handle(array $siteIds): array
    {
        $siteIds = array_values(array_unique(array_map(intval(...), $siteIds)));

        if ($siteIds === []) {
            return ['pivots_deleted' => 0, 'defaults_cleared' => 0];
        }

        $pivotsDeleted = Schema::hasTable('site_user')
            ? DB::table('site_user')->whereIn('site_id', $siteIds)->delete()
            : 0;

        return [
            'pivots_deleted' => $pivotsDeleted,
            'defaults_cleared' => $this->clearDefaultSiteIds($siteIds),
        ];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function clearDefaultSiteIds(array $siteIds): int
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        if (! $tenant instanceof Tenant) {
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

            if (! in_array($siteId, $siteIds, true)) {
                continue;
            }

            unset($settings[$key]);
            $cleared++;
        }

        if ($cleared === 0) {
            return 0;
        }

        $tenant->setAttribute('settings', $settings === [] ? null : $settings);
        $tenant->save();

        return $cleared;
    }
}
