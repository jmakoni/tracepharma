<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_partners', function (Blueprint $table) {
            if (! Schema::hasColumn('trading_partners', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('country_code');
            }
        });

        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('country_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trading_partners', function (Blueprint $table) {
            if (Schema::hasColumn('trading_partners', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });

        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
