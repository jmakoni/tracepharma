<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_partner_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['trading_partner_id', 'product_id']);
        });

        if (Schema::hasColumn('products', 'trading_partner_id')) {
            $now = now();

            DB::table('products')
                ->whereNotNull('trading_partner_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($now): void {
                    $inserts = [];

                    foreach ($rows as $row) {
                        $inserts[] = [
                            'trading_partner_id' => $row->trading_partner_id,
                            'product_id' => $row->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if ($inserts !== []) {
                        DB::table('trading_partner_product')->insertOrIgnore($inserts);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_partner_product');
    }
};
