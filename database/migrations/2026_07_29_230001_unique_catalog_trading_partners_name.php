<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->disambiguateDuplicateNames();

        Schema::table('catalog_trading_partners', function (Blueprint $table) {
            foreach (Schema::getIndexes('catalog_trading_partners') as $index) {
                if ($index['columns'] === ['name'] && ! $index['unique'] && ! $index['primary']) {
                    $table->dropIndex($index['name']);
                }
            }

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_trading_partners', function (Blueprint $table) {
            $table->dropUnique('catalog_trading_partners_name_unique');
            $table->index('name');
        });
    }

    /**
     * Append the row id to the name of every duplicate beyond the first
     * (lowest id) occurrence, so a unique index can be applied.
     */
    private function disambiguateDuplicateNames(): void
    {
        $duplicateNames = DB::table('catalog_trading_partners')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name');

        foreach ($duplicateNames as $name) {
            $ids = DB::table('catalog_trading_partners')
                ->where('name', $name)
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->skip(1) as $id) {
                DB::table('catalog_trading_partners')
                    ->where('id', $id)
                    ->update(['name' => "{$name} ({$id})"]);
            }
        }
    }
};
