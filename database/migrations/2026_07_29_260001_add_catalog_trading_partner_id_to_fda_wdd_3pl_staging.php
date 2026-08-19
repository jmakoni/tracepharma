<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_wdd_3pl_staging', function (Blueprint $table) {
            if (! Schema::hasColumn('fda_wdd_3pl_staging', 'catalog_trading_partner_id')) {
                $table->foreignId('catalog_trading_partner_id')->nullable()->after('id')
                    ->constrained('catalog_trading_partners')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fda_wdd_3pl_staging', function (Blueprint $table) {
            if (Schema::hasColumn('fda_wdd_3pl_staging', 'catalog_trading_partner_id')) {
                $table->dropConstrainedForeignId('catalog_trading_partner_id');
            }
        });
    }
};
