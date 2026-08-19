<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'fda_organizations',
        'fda_establishments',
        'fda_establishment_operations',
        'fda_wdd_facilities',
        'fda_wdd_licenses',
        'fda_products',
        'fda_product_packaging',
        'fda_product_active_ingredients',
        'fda_product_routes',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'manually_edited_fields')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->json('manually_edited_fields')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'manually_edited_fields')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('manually_edited_fields');
            });
        }
    }
};
