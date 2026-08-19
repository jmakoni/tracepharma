<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('fda_product_id')->nullable()->after('catalog_product_id')->index();
            $table->string('package_ndc', 50)->nullable()->after('ndc');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['fda_product_id', 'package_ndc']);
        });
    }
};
