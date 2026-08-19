<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_products', 'fda_product_id')) {
                $table->foreignId('fda_product_id')->nullable()->after('id')->constrained('fda_products')->nullOnDelete();
            }
            if (! Schema::hasColumn('catalog_products', 'package_ndc')) {
                $table->string('package_ndc', 50)->nullable()->after('ndc');
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_products', 'fda_product_id')) {
                $table->dropConstrainedForeignId('fda_product_id');
            }
            if (Schema::hasColumn('catalog_products', 'package_ndc')) {
                $table->dropColumn('package_ndc');
            }
        });
    }
};
