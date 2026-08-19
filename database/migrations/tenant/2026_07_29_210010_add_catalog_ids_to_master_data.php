<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_product_id')->nullable()->after('id')->index();
        });

        Schema::table('trading_partners', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_trading_partner_id')->nullable()->after('id')->index();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_site_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('catalog_product_id');
        });

        Schema::table('trading_partners', function (Blueprint $table) {
            $table->dropColumn('catalog_trading_partner_id');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('catalog_site_id');
        });
    }
};
