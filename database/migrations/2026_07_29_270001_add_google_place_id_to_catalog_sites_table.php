<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_sites', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_sites', 'google_place_id')) {
                $table->string('google_place_id', 255)->nullable()->unique()->after('gln');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_sites', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_sites', 'google_place_id')) {
                $table->dropUnique(['google_place_id']);
                $table->dropColumn('google_place_id');
            }
        });
    }
};
