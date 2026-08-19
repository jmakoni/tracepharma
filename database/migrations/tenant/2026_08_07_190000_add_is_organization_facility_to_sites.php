<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * True organization facilities only — not partner HQ sites promoted from Unassigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        if (! Schema::hasColumn('sites', 'is_organization_facility')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->boolean('is_organization_facility')->default(false)->after('is_active');
                $table->index(
                    ['is_organization_facility', 'is_active'],
                    'sites_org_facility_active_index',
                );
            });
        }

        // Real org sites: null TP and not the partner HQ naming convention.
        DB::table('sites')
            ->whereNull('trading_partner_id')
            ->where('name', 'not like', '% - HQ Site')
            ->update(['is_organization_facility' => true]);

        // Always treat canonical org codes as facilities (even if misnamed).
        DB::table('sites')
            ->whereNull('trading_partner_id')
            ->whereIn('code', ['ORG-HQ', 'MAIN'])
            ->update(['is_organization_facility' => true]);

        DB::table('sites')
            ->whereNull('trading_partner_id')
            ->where('name', 'Demo Organization HQ')
            ->update(['is_organization_facility' => true]);

        // Junk partner HQs incorrectly nulled by PromoteUnassignedSitesToOwned.
        DB::table('sites')
            ->whereNull('trading_partner_id')
            ->where('name', 'like', '% - HQ Site')
            ->where(function ($query): void {
                $query->whereNull('code')
                    ->orWhere('code', '')
                    ->orWhereNotIn('code', ['ORG-HQ', 'MAIN']);
            })
            ->update([
                'is_organization_facility' => false,
                'is_active' => false,
            ]);

        $this->markDefaultSitesAsOrganizationFacilities();
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'is_organization_facility')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex('sites_org_facility_active_index');
            $table->dropColumn('is_organization_facility');
        });
    }

    private function markDefaultSitesAsOrganizationFacilities(): void
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        if ($tenant === null) {
            return;
        }

        $settings = is_array($tenant->getAttribute('settings'))
            ? $tenant->getAttribute('settings')
            : [];

        $ids = [];

        foreach (['default_receive_site_id', 'default_ship_from_site_id'] as $key) {
            $siteId = isset($settings[$key]) ? (int) $settings[$key] : null;

            if ($siteId !== null && $siteId > 0) {
                $ids[] = $siteId;
            }
        }

        if ($ids === []) {
            return;
        }

        DB::table('sites')
            ->whereIn('id', array_values(array_unique($ids)))
            ->whereNull('trading_partner_id')
            ->update(['is_organization_facility' => true]);
    }
};
