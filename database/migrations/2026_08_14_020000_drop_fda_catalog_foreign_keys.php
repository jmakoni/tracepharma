<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillOrganizationIds('fda_products');
        $this->backfillOrganizationIds('fda_wdd_3pl_staging');
        $this->backfillOrganizationIds('fda_wdd_3pl_unmatched');

        $this->dropCatalogPointer('fda_products', 'catalog_trading_partner_id', 'fda_products_catalog_trading_partner_id_foreign');
        $this->dropCatalogPointer('fda_wdd_3pl_staging', 'catalog_trading_partner_id', 'fda_wdd_3pl_staging_catalog_trading_partner_id_foreign');
        $this->dropCatalogPointer('fda_wdd_3pl_staging', 'catalog_site_id', 'fda_wdd_3pl_staging_catalog_site_id_foreign');
        $this->dropCatalogPointer('fda_wdd_3pl_unmatched', 'catalog_trading_partner_id', 'fda_wdd_3pl_unmatched_catalog_trading_partner_id_foreign');
    }

    public function down(): void
    {
        $this->restoreCatalogPointer('fda_products', 'catalog_trading_partner_id', 'fda_products_catalog_trading_partner_id_foreign', 'catalog_trading_partners');
        $this->restoreCatalogPointer('fda_wdd_3pl_staging', 'catalog_trading_partner_id', 'fda_wdd_3pl_staging_catalog_trading_partner_id_foreign', 'catalog_trading_partners');
        $this->restoreCatalogPointer('fda_wdd_3pl_staging', 'catalog_site_id', 'fda_wdd_3pl_staging_catalog_site_id_foreign', 'catalog_sites');
        $this->restoreCatalogPointer('fda_wdd_3pl_unmatched', 'catalog_trading_partner_id', 'fda_wdd_3pl_unmatched_catalog_trading_partner_id_foreign', 'catalog_trading_partners');
    }

    private function backfillOrganizationIds(string $table): void
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'catalog_trading_partner_id')
            || ! Schema::hasColumn($table, 'fda_organization_id')
            || ! Schema::hasTable('catalog_trading_partners')
            || ! Schema::hasColumn('catalog_trading_partners', 'fda_organization_id')
        ) {
            return;
        }

        DB::statement(
            "UPDATE `{$table}` AS target
            INNER JOIN catalog_trading_partners AS catalog
                ON catalog.id = target.catalog_trading_partner_id
            SET target.fda_organization_id = catalog.fda_organization_id
            WHERE target.fda_organization_id IS NULL
              AND catalog.fda_organization_id IS NOT NULL"
        );
    }

    private function dropCatalogPointer(string $table, string $column, string $foreign): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $foreign): void {
            if ($this->hasForeignKey($table, $foreign)) {
                $blueprint->dropForeign($foreign);
            }

            $blueprint->dropColumn($column);
        });
    }

    private function restoreCatalogPointer(string $table, string $column, string $foreign, string $references): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $foreign, $references): void {
            $blueprint->unsignedBigInteger($column)->nullable();
            $blueprint->foreign($column, $foreign)
                ->references('id')
                ->on($references)
                ->nullOnDelete();
        });
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND CONSTRAINT_NAME = ?
              AND CONSTRAINT_TYPE = ?',
            [$table, $name, 'FOREIGN KEY']
        );

        return $row !== null;
    }
};
