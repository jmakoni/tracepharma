<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_products', function (Blueprint $table): void {
            if (! Schema::hasColumn('fda_products', 'name')) {
                $table->text('name')->nullable()->after('brand_name_base');
            }
            if (! Schema::hasColumn('fda_products', 'strength')) {
                $table->string('strength', 255)->nullable()->after('dosage_form');
            }
            if (! Schema::hasColumn('fda_products', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });

        $this->ensureProductNameIsText();

        DB::table('fda_products')->whereNull('name')->update([
            'name' => DB::raw('COALESCE(brand_name, generic_name, product_ndc)'),
        ]);

        Schema::table('fda_product_packaging', function (Blueprint $table): void {
            if (! Schema::hasColumn('fda_product_packaging', 'ndc11')) {
                $table->char('ndc11', 11)->nullable()->unique();
            }
            if (! Schema::hasColumn('fda_product_packaging', 'gtin')) {
                $table->string('gtin', 14)->nullable()->unique();
            }
            if (! Schema::hasColumn('fda_product_packaging', 'net_content_description')) {
                $table->text('net_content_description')->nullable();
            }
            if (! Schema::hasColumn('fda_product_packaging', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn('fda_product_packaging', 'created_at')) {
                $table->timestamp('created_at')->nullable()->useCurrent();
            }
            if (! Schema::hasColumn('fda_product_packaging', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            }
        });

        $now = now();
        DB::table('fda_product_packaging')
            ->whereNull('created_at')
            ->update(['created_at' => $now, 'updated_at' => $now]);

        if (Schema::hasTable('fda_product_active_ingredients')
            && ! Schema::hasIndex('fda_product_active_ingredients', 'fda_prod_ing_unique')) {
            DB::statement(<<<'SQL'
DELETE t1 FROM fda_product_active_ingredients t1
INNER JOIN fda_product_active_ingredients t2
    ON t1.product_id_fk = t2.product_id_fk
    AND t1.name = t2.name
    AND t1.strength <=> t2.strength
    AND t1.id > t2.id
SQL);

            DB::statement(
                'ALTER TABLE fda_product_active_ingredients ADD UNIQUE INDEX fda_prod_ing_unique (product_id_fk, name(191), strength)'
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('fda_product_active_ingredients', 'fda_prod_ing_unique')) {
            Schema::table('fda_product_active_ingredients', function (Blueprint $table): void {
                if (! Schema::hasIndex('fda_product_active_ingredients', 'fda_prod_ing_product_id_fk_idx')) {
                    $table->index('product_id_fk', 'fda_prod_ing_product_id_fk_idx');
                }
                $table->dropUnique('fda_prod_ing_unique');
            });
        }

        $packaging = array_values(array_filter(
            ['ndc11', 'gtin', 'net_content_description', 'is_active', 'created_at', 'updated_at'],
            static fn (string $column): bool => Schema::hasColumn('fda_product_packaging', $column)
        ));

        if ($packaging !== []) {
            Schema::table('fda_product_packaging', function (Blueprint $table) use ($packaging): void {
                $table->dropColumn($packaging);
            });
        }

        $products = array_values(array_filter(
            ['name', 'strength', 'is_active'],
            static fn (string $column): bool => Schema::hasColumn('fda_products', $column)
        ));

        if ($products !== []) {
            Schema::table('fda_products', function (Blueprint $table) use ($products): void {
                $table->dropColumn($products);
            });
        }
    }

    private function ensureProductNameIsText(): void
    {
        if (! Schema::hasColumn('fda_products', 'name')) {
            return;
        }

        $type = DB::selectOne(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['fda_products', 'name']
        );

        if ($type !== null && strtolower((string) $type->DATA_TYPE) !== 'text') {
            DB::statement('ALTER TABLE fda_products MODIFY name TEXT NULL');
        }
    }
};
