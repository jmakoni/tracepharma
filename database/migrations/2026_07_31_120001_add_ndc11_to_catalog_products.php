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
        Schema::table('catalog_products', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_products', 'ndc11')) {
                $table->char('ndc11', 11)->nullable()->after('package_ndc');
                $table->unique('ndc11');
            }
        });

        DB::table('catalog_products')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $ndc11 = Ndc::derive($row->package_ndc, $row->ndc);

                    if ($ndc11 === null) {
                        continue;
                    }

                    DB::table('catalog_products')
                        ->where('id', $row->id)
                        ->update(['ndc11' => $ndc11]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('catalog_products', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_products', 'ndc11')) {
                $table->dropUnique(['ndc11']);
                $table->dropColumn('ndc11');
            }
        });
    }
};
