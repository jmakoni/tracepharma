<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A catalog ATP license describes one facility, so its identity is the site plus
 * the state/number pair. The old tenant-wide unique key made the FDA promote
 * move a single row between sites instead of recording one license per facility.
 *
 * Widening a unique key cannot collide with rows the narrow key already allowed,
 * so up() needs no de-duplication; down() collapses each group first.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'catalog_atp_licenses_license_state_license_number_unique';

    private const NEW_UNIQUE = 'catalog_atp_licenses_site_state_number_unique';

    private const SITE_INDEX = 'catalog_atp_licenses_site_index';

    public function up(): void
    {
        if (! Schema::hasTable('catalog_atp_licenses')) {
            return;
        }

        if (Schema::hasIndex('catalog_atp_licenses', self::OLD_UNIQUE)) {
            Schema::table('catalog_atp_licenses', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }

        if (! Schema::hasIndex('catalog_atp_licenses', self::NEW_UNIQUE)) {
            Schema::table('catalog_atp_licenses', function (Blueprint $table): void {
                $table->unique(['catalog_site_id', 'license_state', 'license_number'], self::NEW_UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('catalog_atp_licenses')) {
            return;
        }

        // The catalog_site_id foreign key leans on the site-leading unique this
        // migration added, so it needs its own index before that one can go.
        if (! Schema::hasIndex('catalog_atp_licenses', self::SITE_INDEX)) {
            Schema::table('catalog_atp_licenses', function (Blueprint $table): void {
                $table->index('catalog_site_id', self::SITE_INDEX);
            });
        }

        if (Schema::hasIndex('catalog_atp_licenses', self::NEW_UNIQUE)) {
            Schema::table('catalog_atp_licenses', function (Blueprint $table): void {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        $this->collapseDuplicateLicenses();

        if (! Schema::hasIndex('catalog_atp_licenses', self::OLD_UNIQUE)) {
            Schema::table('catalog_atp_licenses', function (Blueprint $table): void {
                $table->unique(['license_state', 'license_number'], self::OLD_UNIQUE);
            });
        }
    }

    /**
     * Keep one row per state/number: a known expiration before an unknown one,
     * then the latest expiration, then the oldest row.
     */
    private function collapseDuplicateLicenses(): void
    {
        $duplicates = DB::table('catalog_atp_licenses')
            ->select('license_state', 'license_number')
            ->groupBy('license_state', 'license_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = DB::table('catalog_atp_licenses')
                ->where('license_state', $duplicate->license_state)
                ->where('license_number', $duplicate->license_number)
                ->orderByRaw('license_expiration_date IS NULL')
                ->orderByDesc('license_expiration_date')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            $discarded = array_slice($ids, 1);

            if ($discarded !== []) {
                DB::table('catalog_atp_licenses')->whereIn('id', $discarded)->delete();
            }
        }
    }
};
