<?php

use App\Support\Gs1\Ndc;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'ndc11')) {
                $table->char('ndc11', 11)->nullable()->after('package_ndc');
            }
        });

        DB::table('products')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $ndc11 = Ndc::derive($row->package_ndc, $row->ndc);

                    if ($ndc11 === null) {
                        continue;
                    }

                    DB::table('products')
                        ->where('id', $row->id)
                        ->update(['ndc11' => $ndc11]);
                }
            });

        // Clear duplicate ndc11 values (keep lowest id) before unique index.
        $duplicateNdc11s = DB::table('products')
            ->select('ndc11')
            ->whereNotNull('ndc11')
            ->groupBy('ndc11')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ndc11');

        foreach ($duplicateNdc11s as $ndc11) {
            $keepId = DB::table('products')->where('ndc11', $ndc11)->orderBy('id')->value('id');

            DB::table('products')
                ->where('ndc11', $ndc11)
                ->where('id', '!=', $keepId)
                ->update(['ndc11' => null]);
        }

        $ndc11Index = collect(DB::select('SHOW INDEX FROM products WHERE Key_name = ?', ['products_ndc11_unique']));

        if ($ndc11Index->isEmpty() && Schema::hasColumn('products', 'ndc11')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique('ndc11', 'products_ndc11_unique');
            });
        }

        if (Schema::hasColumn('products', 'gtin')) {
            DB::statement('ALTER TABLE products MODIFY gtin VARCHAR(14) NULL');
        }

        Schema::table('trading_partner_product', function (Blueprint $table) {
            if (! Schema::hasColumn('trading_partner_product', 'partner_item_number')) {
                $table->string('partner_item_number', 64)->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('trading_partner_product', 'uom_code')) {
                $table->string('uom_code', 8)->nullable()->after('partner_item_number');
            }
            if (! Schema::hasColumn('trading_partner_product', 'units_per_case')) {
                $table->unsignedInteger('units_per_case')->nullable()->after('uom_code');
            }
            if (! Schema::hasColumn('trading_partner_product', 'authorization_status')) {
                $table->string('authorization_status', 32)->default('authorized')->after('units_per_case');
            }
            if (! Schema::hasColumn('trading_partner_product', 'authorized_at')) {
                $table->timestamp('authorized_at')->nullable()->after('authorization_status');
            }
            if (! Schema::hasColumn('trading_partner_product', 'is_primary')) {
                $table->boolean('is_primary')->default(false)->after('authorized_at');
            }
        });

        $partnerItemIndex = collect(DB::select(
            'SHOW INDEX FROM trading_partner_product WHERE Key_name = ?',
            ['tpp_partner_item_unique']
        ));

        if ($partnerItemIndex->isEmpty() && Schema::hasColumn('trading_partner_product', 'partner_item_number')) {
            Schema::table('trading_partner_product', function (Blueprint $table) {
                $table->unique(['trading_partner_id', 'partner_item_number'], 'tpp_partner_item_unique');
            });
        }

        if (! Schema::hasTable('product_packaging_links')) {
            Schema::create('product_packaging_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('child_product_id')->constrained('products')->cascadeOnDelete();
                $table->unsignedInteger('quantity');
                $table->string('pack_level', 16);
                $table->timestamps();

                $table->unique(['parent_product_id', 'child_product_id'], 'ppl_parent_child_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_packaging_links');

        Schema::table('trading_partner_product', function (Blueprint $table) {
            if (Schema::hasColumn('trading_partner_product', 'partner_item_number')) {
                $table->dropUnique('tpp_partner_item_unique');
            }

            $columns = array_filter([
                'partner_item_number',
                'uom_code',
                'units_per_case',
                'authorization_status',
                'authorized_at',
                'is_primary',
            ], fn (string $column): bool => Schema::hasColumn('trading_partner_product', $column));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'ndc11')) {
                $table->dropUnique('products_ndc11_unique');
                $table->dropColumn('ndc11');
            }
        });

        if (Schema::hasColumn('products', 'gtin')) {
            DB::statement('ALTER TABLE products MODIFY gtin VARCHAR(14) NOT NULL');
        }
    }
};
