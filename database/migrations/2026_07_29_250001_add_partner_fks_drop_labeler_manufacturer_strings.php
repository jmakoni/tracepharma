<?php

use App\Enums\PartnerType;
use App\Support\PartnerSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureCatalogPharmaIncPartnerExists();

        if (! Schema::hasColumn('fda_products', 'catalog_trading_partner_id')) {
            Schema::table('fda_products', function (Blueprint $table) {
                $table->foreignId('catalog_trading_partner_id')->nullable()->after('labeler_name')
                    ->constrained('catalog_trading_partners')->nullOnDelete();
            });
        }

        $this->backfillFdaProductsFromPivot();
        $this->backfillFdaProductsBySlug();

        if (! Schema::hasColumn('catalog_products', 'catalog_trading_partner_id')) {
            Schema::table('catalog_products', function (Blueprint $table) {
                $table->foreignId('catalog_trading_partner_id')->nullable()->after('manufacturer_name')
                    ->constrained('catalog_trading_partners')->nullOnDelete();
            });
        }

        $this->backfillCatalogProductsBySlug();
        $this->backfillCatalogProductsFromFdaProducts();

        if (Schema::hasColumn('fda_products', 'labeler_name')) {
            Schema::table('fda_products', function (Blueprint $table) {
                $table->dropColumn('labeler_name');
            });
        }

        if (Schema::hasColumn('catalog_products', 'manufacturer_name')) {
            Schema::table('catalog_products', function (Blueprint $table) {
                $table->dropColumn('manufacturer_name');
            });
        }

        Schema::dropIfExists('fda_product_trading_partner');
    }

    public function down(): void
    {
        Schema::create('fda_product_trading_partner', function (Blueprint $table) {
            $table->foreignId('fda_product_id')->constrained('fda_products')->cascadeOnDelete();
            $table->foreignId('trading_partner_id')->constrained('catalog_trading_partners')->cascadeOnDelete();
            $table->primary(['fda_product_id', 'trading_partner_id']);
        });

        if (! Schema::hasColumn('fda_products', 'labeler_name')) {
            Schema::table('fda_products', function (Blueprint $table) {
                $table->string('labeler_name')->nullable()->after('brand_name_base');
            });
        }

        if (! Schema::hasColumn('catalog_products', 'manufacturer_name')) {
            Schema::table('catalog_products', function (Blueprint $table) {
                $table->string('manufacturer_name')->nullable()->after('strength');
            });
        }

        if (Schema::hasColumn('catalog_products', 'catalog_trading_partner_id')) {
            Schema::table('catalog_products', function (Blueprint $table) {
                $table->dropConstrainedForeignId('catalog_trading_partner_id');
            });
        }

        if (Schema::hasColumn('fda_products', 'catalog_trading_partner_id')) {
            Schema::table('fda_products', function (Blueprint $table) {
                $table->dropConstrainedForeignId('catalog_trading_partner_id');
            });
        }
    }

    /**
     * Guarantee the demo "Catalog Pharma Inc" manufacturer exists so the
     * demo FDA/catalog product rows have somewhere to link during backfill.
     */
    private function ensureCatalogPharmaIncPartnerExists(): void
    {
        if (! Schema::hasTable('catalog_trading_partners')
            || ! Schema::hasColumn('catalog_trading_partners', 'slug')
        ) {
            return;
        }

        if (DB::table('catalog_trading_partners')->where('slug', 'catalog-pharma-inc')->exists()) {
            return;
        }

        $now = now();
        $row = [
            'slug' => 'catalog-pharma-inc',
            'name' => 'Catalog Pharma Inc',
            'partner_type' => PartnerType::Manufacturer->value,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('catalog_trading_partners', 'country_code')) {
            $row['country_code'] = 'US';
        }

        DB::table('catalog_trading_partners')->insert($row);
    }

    /**
     * Fastest, most-trusted source: the existing many-to-many pivot.
     * Where a fda_product had more than one linked partner, keep the
     * lowest trading_partner_id (matches prior "first wins" semantics).
     */
    private function backfillFdaProductsFromPivot(): void
    {
        if (! Schema::hasTable('fda_product_trading_partner')) {
            return;
        }

        DB::statement('
            UPDATE fda_products fp
            JOIN (
                SELECT fda_product_id, MIN(trading_partner_id) AS trading_partner_id
                FROM fda_product_trading_partner
                GROUP BY fda_product_id
            ) pivot_min ON pivot_min.fda_product_id = fp.id
            SET fp.catalog_trading_partner_id = pivot_min.trading_partner_id
            WHERE fp.catalog_trading_partner_id IS NULL
        ');
    }

    /**
     * Fallback for rows the pivot never covered: derive the partner from
     * labeler_name via the same slug used to create catalog_trading_partners.
     */
    private function backfillFdaProductsBySlug(): void
    {
        if (! Schema::hasColumn('fda_products', 'labeler_name')) {
            return;
        }

        $partnerIdsBySlug = DB::table('catalog_trading_partners')->pluck('id', 'slug')->all();

        if ($partnerIdsBySlug === []) {
            return;
        }

        Schema::dropIfExists('tmp_fda_partner_backfill');
        Schema::create('tmp_fda_partner_backfill', function (Blueprint $table) {
            $table->unsignedBigInteger('fda_product_id')->primary();
            $table->unsignedBigInteger('catalog_trading_partner_id');
        });

        DB::table('fda_products')
            ->whereNull('catalog_trading_partner_id')
            ->whereNotNull('labeler_name')
            ->where('labeler_name', '!=', '')
            ->select('id', 'labeler_name')
            ->chunkById(2000, function ($rows) use ($partnerIdsBySlug) {
                $insertRows = [];

                foreach ($rows as $row) {
                    $slug = PartnerSlug::from((string) $row->labeler_name);
                    $partnerId = $partnerIdsBySlug[$slug] ?? null;

                    if ($partnerId !== null) {
                        $insertRows[] = [
                            'fda_product_id' => $row->id,
                            'catalog_trading_partner_id' => $partnerId,
                        ];
                    }
                }

                if ($insertRows !== []) {
                    DB::table('tmp_fda_partner_backfill')->insert($insertRows);
                }
            });

        DB::statement('
            UPDATE fda_products fp
            JOIN tmp_fda_partner_backfill t ON t.fda_product_id = fp.id
            SET fp.catalog_trading_partner_id = t.catalog_trading_partner_id
            WHERE fp.catalog_trading_partner_id IS NULL
        ');

        Schema::dropIfExists('tmp_fda_partner_backfill');
    }

    /**
     * Derive catalog_products.catalog_trading_partner_id from
     * manufacturer_name via the manufacturer slug.
     */
    private function backfillCatalogProductsBySlug(): void
    {
        if (! Schema::hasColumn('catalog_products', 'manufacturer_name')) {
            return;
        }

        $partnerIdsBySlug = DB::table('catalog_trading_partners')->pluck('id', 'slug')->all();

        if ($partnerIdsBySlug === []) {
            return;
        }

        Schema::dropIfExists('tmp_catalog_product_partner_backfill');
        Schema::create('tmp_catalog_product_partner_backfill', function (Blueprint $table) {
            $table->unsignedBigInteger('catalog_product_id')->primary();
            $table->unsignedBigInteger('catalog_trading_partner_id');
        });

        DB::table('catalog_products')
            ->whereNull('catalog_trading_partner_id')
            ->whereNotNull('manufacturer_name')
            ->where('manufacturer_name', '!=', '')
            ->select('id', 'manufacturer_name')
            ->chunkById(2000, function ($rows) use ($partnerIdsBySlug) {
                $insertRows = [];

                foreach ($rows as $row) {
                    $slug = PartnerSlug::from((string) $row->manufacturer_name);
                    $partnerId = $partnerIdsBySlug[$slug] ?? null;

                    if ($partnerId !== null) {
                        $insertRows[] = [
                            'catalog_product_id' => $row->id,
                            'catalog_trading_partner_id' => $partnerId,
                        ];
                    }
                }

                if ($insertRows !== []) {
                    DB::table('tmp_catalog_product_partner_backfill')->insert($insertRows);
                }
            });

        DB::statement('
            UPDATE catalog_products cp
            JOIN tmp_catalog_product_partner_backfill t ON t.catalog_product_id = cp.id
            SET cp.catalog_trading_partner_id = t.catalog_trading_partner_id
            WHERE cp.catalog_trading_partner_id IS NULL
        ');

        Schema::dropIfExists('tmp_catalog_product_partner_backfill');
    }

    /**
     * Last resort: copy the linked fda_products partner across for any
     * catalog_products row still missing one.
     */
    private function backfillCatalogProductsFromFdaProducts(): void
    {
        if (! Schema::hasColumn('catalog_products', 'fda_product_id') || ! Schema::hasColumn('fda_products', 'catalog_trading_partner_id')) {
            return;
        }

        DB::statement('
            UPDATE catalog_products cp
            JOIN fda_products fp ON fp.id = cp.fda_product_id
            SET cp.catalog_trading_partner_id = fp.catalog_trading_partner_id
            WHERE cp.catalog_trading_partner_id IS NULL
              AND fp.catalog_trading_partner_id IS NOT NULL
        ');
    }
};
