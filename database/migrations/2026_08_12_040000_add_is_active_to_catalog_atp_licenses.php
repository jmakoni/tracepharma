<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A licence the FDA report no longer lists is delisted, not deleted: the row stays as
 * evidence of what we once held while ceasing to authorize anything. Existing rows
 * default to active so the column can be added without a data backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_atp_licenses') || Schema::hasColumn('catalog_atp_licenses', 'is_active')) {
            return;
        }

        Schema::table('catalog_atp_licenses', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('license_expiration_date');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('catalog_atp_licenses') || ! Schema::hasColumn('catalog_atp_licenses', 'is_active')) {
            return;
        }

        Schema::table('catalog_atp_licenses', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
