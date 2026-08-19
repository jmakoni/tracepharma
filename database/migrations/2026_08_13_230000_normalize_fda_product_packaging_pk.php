<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fda_product_packaging')) {
            return;
        }

        $this->addSurrogateId();
        $this->addAndBackfillFdaProductId();
        $this->assertEveryRowHasAValidProduct();
        $this->makeFdaProductIdRequiredWithForeignKey();
        $this->switchPrimaryKeyToId();
        $this->dropProductIdFk();
    }

    public function down(): void
    {
        if (! Schema::hasTable('fda_product_packaging')
            || ! Schema::hasColumn('fda_product_packaging', 'id')
            || ! Schema::hasColumn('fda_product_packaging', 'fda_product_id')) {
            return;
        }

        $this->restoreProductIdFk();
        $this->switchPrimaryKeyToPackageNdc();
        $this->dropLaravelColumns();
    }

    private function addSurrogateId(): void
    {
        if (Schema::hasColumn('fda_product_packaging', 'id')) {
            return;
        }

        DB::statement(
            'ALTER TABLE fda_product_packaging
            ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE FIRST'
        );
    }

    private function addAndBackfillFdaProductId(): void
    {
        if (! Schema::hasColumn('fda_product_packaging', 'fda_product_id')) {
            Schema::table('fda_product_packaging', function (Blueprint $table): void {
                $table->unsignedBigInteger('fda_product_id')->nullable()->after('id');
            });
        }

        if (! Schema::hasColumn('fda_product_packaging', 'product_id_fk')) {
            return;
        }

        DB::statement(
            'UPDATE fda_product_packaging
            SET fda_product_id = product_id_fk
            WHERE fda_product_id IS NULL'
        );
    }

    private function assertEveryRowHasAValidProduct(): void
    {
        $nulls = DB::table('fda_product_packaging')->whereNull('fda_product_id')->count();
        $orphans = DB::table('fda_product_packaging as p')
            ->leftJoin('fda_products as fp', 'fp.id', '=', 'p.fda_product_id')
            ->whereNull('fp.id')
            ->count();

        if ($nulls > 0 || $orphans > 0) {
            throw new \RuntimeException(
                "fda_product_packaging backfill failed: {$nulls} null fda_product_id, {$orphans} orphan rows. Refusing to drop product_id_fk."
            );
        }
    }

    private function makeFdaProductIdRequiredWithForeignKey(): void
    {
        $nullable = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['fda_product_packaging', 'fda_product_id']
        );

        if ($nullable !== null && strtoupper((string) $nullable->IS_NULLABLE) === 'YES') {
            DB::statement('ALTER TABLE fda_product_packaging MODIFY fda_product_id BIGINT UNSIGNED NOT NULL');
        }

        if (! Schema::hasIndex('fda_product_packaging', 'fda_product_packaging_fda_product_id_index')) {
            Schema::table('fda_product_packaging', function (Blueprint $table): void {
                $table->index('fda_product_id', 'fda_product_packaging_fda_product_id_index');
            });
        }

        if (! $this->hasForeignKey('fda_product_packaging_fda_product_id_foreign')) {
            Schema::table('fda_product_packaging', function (Blueprint $table): void {
                $table->foreign('fda_product_id', 'fda_product_packaging_fda_product_id_foreign')
                    ->references('id')
                    ->on('fda_products')
                    ->cascadeOnDelete();
            });
        }
    }

    private function switchPrimaryKeyToId(): void
    {
        if ($this->primaryKeyColumn() === 'id') {
            $this->ensurePackageNdcUnique();
            $this->dropRedundantIdUnique();

            return;
        }

        $this->dropForeignKey('fda_product_packaging_product_id_fk_foreign');

        DB::statement('ALTER TABLE fda_product_packaging DROP PRIMARY KEY, ADD PRIMARY KEY (id)');

        $this->ensurePackageNdcUnique();
        $this->dropRedundantIdUnique();
    }

    private function dropProductIdFk(): void
    {
        if (! Schema::hasColumn('fda_product_packaging', 'product_id_fk')) {
            return;
        }

        $this->dropForeignKey('fda_product_packaging_product_id_fk_foreign');

        Schema::table('fda_product_packaging', function (Blueprint $table): void {
            $table->dropColumn('product_id_fk');
        });
    }

    private function restoreProductIdFk(): void
    {
        if (! Schema::hasColumn('fda_product_packaging', 'product_id_fk')) {
            Schema::table('fda_product_packaging', function (Blueprint $table): void {
                $table->unsignedBigInteger('product_id_fk')->nullable()->after('package_ndc');
            });
        }

        DB::statement(
            'UPDATE fda_product_packaging
            SET product_id_fk = fda_product_id
            WHERE product_id_fk IS NULL'
        );

        DB::statement('ALTER TABLE fda_product_packaging MODIFY product_id_fk BIGINT UNSIGNED NOT NULL');

        if (! $this->hasForeignKey('fda_product_packaging_product_id_fk_foreign')) {
            Schema::table('fda_product_packaging', function (Blueprint $table): void {
                $table->foreign('product_id_fk', 'fda_product_packaging_product_id_fk_foreign')
                    ->references('id')
                    ->on('fda_products')
                    ->cascadeOnDelete();
            });
        }
    }

    private function switchPrimaryKeyToPackageNdc(): void
    {
        if ($this->primaryKeyColumn() === 'package_ndc') {
            return;
        }

        $this->dropForeignKey('fda_product_packaging_fda_product_id_foreign');

        DB::statement('ALTER TABLE fda_product_packaging MODIFY id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE fda_product_packaging DROP PRIMARY KEY, ADD PRIMARY KEY (package_ndc)');

        if (Schema::hasIndex('fda_product_packaging', 'fda_product_packaging_package_ndc_unique')) {
            Schema::table('fda_product_packaging', function (Blueprint $table): void {
                $table->dropUnique('fda_product_packaging_package_ndc_unique');
            });
        }
    }

    private function dropLaravelColumns(): void
    {
        if (Schema::hasColumn('fda_product_packaging', 'id')) {
            Schema::table('fda_product_packaging', function (Blueprint $table): void {
                $table->dropColumn('id');
            });
        }

        if (Schema::hasColumn('fda_product_packaging', 'fda_product_id')) {
            Schema::table('fda_product_packaging', function (Blueprint $table): void {
                $table->dropColumn('fda_product_id');
            });
        }
    }

    private function ensurePackageNdcUnique(): void
    {
        if (Schema::hasIndex('fda_product_packaging', 'fda_product_packaging_package_ndc_unique')) {
            return;
        }

        Schema::table('fda_product_packaging', function (Blueprint $table): void {
            $table->unique('package_ndc', 'fda_product_packaging_package_ndc_unique');
        });
    }

    private function dropRedundantIdUnique(): void
    {
        if (! Schema::hasIndex('fda_product_packaging', 'id')) {
            return;
        }

        Schema::table('fda_product_packaging', function (Blueprint $table): void {
            $table->dropUnique('id');
        });
    }

    private function primaryKeyColumn(): ?string
    {
        $row = DB::selectOne(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND INDEX_NAME = ?
            ORDER BY SEQ_IN_INDEX
            LIMIT 1',
            ['fda_product_packaging', 'PRIMARY']
        );

        return $row?->COLUMN_NAME;
    }

    private function hasForeignKey(string $name): bool
    {
        return DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND CONSTRAINT_TYPE = ?
              AND CONSTRAINT_NAME = ?',
            ['fda_product_packaging', 'FOREIGN KEY', $name]
        ) !== null;
    }

    private function dropForeignKey(string $name): void
    {
        if (! $this->hasForeignKey($name)) {
            return;
        }

        Schema::table('fda_product_packaging', function (Blueprint $table) use ($name): void {
            $table->dropForeign($name);
        });
    }
};
