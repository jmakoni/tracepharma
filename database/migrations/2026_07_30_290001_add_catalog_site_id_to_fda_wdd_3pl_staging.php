<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_wdd_3pl_staging', function (Blueprint $table) {
            if (! Schema::hasColumn('fda_wdd_3pl_staging', 'catalog_site_id')) {
                $table->foreignId('catalog_site_id')->nullable()->after('catalog_trading_partner_id')
                    ->constrained('catalog_sites')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fda_wdd_3pl_staging', function (Blueprint $table) {
            if (Schema::hasColumn('fda_wdd_3pl_staging', 'catalog_site_id')) {
                $table->dropConstrainedForeignId('catalog_site_id');
            }
        });
    }
};
