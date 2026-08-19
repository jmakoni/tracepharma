<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ATP licenses belong to a site, not to a tenant: the same state/number pair can
 * legitimately appear on more than one site (a partner HQ and one of its docks),
 * so the unique key has to include site_id.
 *
 * Widening a unique key can never collide with rows the narrow key already
 * allowed, so up() needs no de-duplication. down() does: it collapses each
 * state/number group to the row worth keeping before the narrow key returns.
 *
 * catalog_atp_license_id records which central catalog license a row was synced
 * from, so sync can deactivate rows whose catalog counterpart disappeared
 * without touching licenses entered by hand.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'atp_licenses_license_state_license_number_unique';

    private const NEW_UNIQUE = 'atp_licenses_site_state_number_unique';

    private const SITE_INDEX = 'atp_licenses_site_index';

    public function up(): void
    {
        if (! Schema::hasTable('atp_licenses')) {
            return;
        }

        $hasCatalogLicenseId = Schema::hasColumn('atp_licenses', 'catalog_atp_license_id');
        $hasIsActive = Schema::hasColumn('atp_licenses', 'is_active');

        if (! $hasCatalogLicenseId || ! $hasIsActive) {
            Schema::table('atp_licenses', function (Blueprint $table) use ($hasCatalogLicenseId, $hasIsActive): void {
                if (! $hasCatalogLicenseId) {
                    // Central-database id: indexed for sync, never a cross-database FK.
                    $table->unsignedBigInteger('catalog_atp_license_id')->nullable()->after('site_id');
                    $table->index('catalog_atp_license_id', 'atp_licenses_catalog_license_index');
                }

                if (! $hasIsActive) {
                    $table->boolean('is_active')->default(true)->after('facility_contact_email');
                    $table->index(['site_id', 'is_active'], 'atp_licenses_site_active_index');
                }
            });
        }

        if (Schema::hasIndex('atp_licenses', self::OLD_UNIQUE)) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }

        if (! Schema::hasIndex('atp_licenses', self::NEW_UNIQUE)) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->unique(['site_id', 'license_state', 'license_number'], self::NEW_UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('atp_licenses')) {
            return;
        }

        // The site_id foreign key leans on the site-leading indexes this migration
        // added, so it needs its own index before those can go.
        if (! Schema::hasIndex('atp_licenses', self::SITE_INDEX)) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->index('site_id', self::SITE_INDEX);
            });
        }

        if (Schema::hasIndex('atp_licenses', self::NEW_UNIQUE)) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        $this->collapseDuplicateLicenses();

        if (! Schema::hasIndex('atp_licenses', self::OLD_UNIQUE)) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->unique(['license_state', 'license_number'], self::OLD_UNIQUE);
            });
        }

        Schema::table('atp_licenses', function (Blueprint $table): void {
            if (Schema::hasColumn('atp_licenses', 'is_active')) {
                $table->dropIndex('atp_licenses_site_active_index');
                $table->dropColumn('is_active');
            }

            if (Schema::hasColumn('atp_licenses', 'catalog_atp_license_id')) {
                $table->dropIndex('atp_licenses_catalog_license_index');
                $table->dropColumn('catalog_atp_license_id');
            }
        });
    }

    /**
     * Keep one row per state/number: active before deactivated, a known
     * expiration before an unknown one, then the latest expiration, then the
     * oldest row. Everything else in the group goes.
     */
    private function collapseDuplicateLicenses(): void
    {
        $duplicates = DB::table('atp_licenses')
            ->select('license_state', 'license_number')
            ->groupBy('license_state', 'license_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $hasIsActive = Schema::hasColumn('atp_licenses', 'is_active');

        foreach ($duplicates as $duplicate) {
            $group = DB::table('atp_licenses')
                ->where('license_state', $duplicate->license_state)
                ->where('license_number', $duplicate->license_number);

            if ($hasIsActive) {
                $group->orderByRaw('is_active = 0');
            }

            $ids = $group
                ->orderByRaw('license_expiration_date IS NULL')
                ->orderByDesc('license_expiration_date')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $discarded = array_slice($ids, 1);

            if ($discarded !== []) {
                DB::table('atp_licenses')->whereIn('id', $discarded)->delete();
            }
        }
    }
};
