<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deploy note: run `php artisan tenants:migrate --force` on stage/prod (and any
     * non-demo tenants) so license_country + widened license_state land before ATP forms
     * write multi-country rows.
     */
    private const OLD_UNIQUE = 'atp_licenses_site_state_number_unique';

    private const NEW_UNIQUE = 'atp_licenses_site_country_state_number_unique';

    public function up(): void
    {
        if (! Schema::hasTable('atp_licenses')) {
            return;
        }

        Schema::table('atp_licenses', function (Blueprint $table): void {
            if (! Schema::hasColumn('atp_licenses', 'license_country')) {
                $table->char('license_country', 2)->default('US')->after('license_number');
            }
        });

        DB::statement('ALTER TABLE atp_licenses MODIFY license_state VARCHAR(16) NOT NULL');

        if (Schema::hasIndex('atp_licenses', self::OLD_UNIQUE)) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }

        if (! Schema::hasIndex('atp_licenses', self::NEW_UNIQUE)) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->unique(
                    ['site_id', 'license_country', 'license_state', 'license_number'],
                    self::NEW_UNIQUE,
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('atp_licenses')) {
            return;
        }

        if (Schema::hasIndex('atp_licenses', self::NEW_UNIQUE)) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        if (! Schema::hasIndex('atp_licenses', self::OLD_UNIQUE)) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->unique(['site_id', 'license_state', 'license_number'], self::OLD_UNIQUE);
            });
        }

        DB::statement('ALTER TABLE atp_licenses MODIFY license_state VARCHAR(2) NOT NULL');

        if (Schema::hasColumn('atp_licenses', 'license_country')) {
            Schema::table('atp_licenses', function (Blueprint $table): void {
                $table->dropColumn('license_country');
            });
        }
    }
};
