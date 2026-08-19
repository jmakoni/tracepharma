<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog upsert resolves a package by its FDA package NDC before falling back
 * to GTIN, so that lookup must be indexed for full-directory imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasColumn('catalog_products', 'package_ndc')
            || Schema::hasIndex('catalog_products', 'catalog_products_package_ndc_index')
        ) {
            return;
        }

        Schema::table('catalog_products', function (Blueprint $table): void {
            $table->index('package_ndc', 'catalog_products_package_ndc_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('catalog_products', 'catalog_products_package_ndc_index')) {
            return;
        }

        Schema::table('catalog_products', function (Blueprint $table): void {
            $table->dropIndex('catalog_products_package_ndc_index');
        });
    }
};
