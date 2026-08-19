<?php

use App\Actions\MasterData\DeduplicateCatalogTradingPartnersBySlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_trading_partners', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_trading_partners', 'slug')) {
                $table->string('slug', 191)->nullable()->after('name');
            }
        });

        // Drop name uniqueness before merge (longest-name updates can collide).
        Schema::table('catalog_trading_partners', function (Blueprint $table) {
            if ($this->hasUniqueNameIndex()) {
                $table->dropUnique('catalog_trading_partners_name_unique');
            }

            if (! $this->hasNonUniqueNameIndex()) {
                $table->index('name');
            }
        });

        if (class_exists(DeduplicateCatalogTradingPartnersBySlug::class)) {
            app(DeduplicateCatalogTradingPartnersBySlug::class)->handle();
        }

        if (! $this->hasUniqueSlugIndex()) {
            Schema::table('catalog_trading_partners', function (Blueprint $table) {
                $table->unique('slug');
            });
        }

        DB::statement('ALTER TABLE catalog_trading_partners MODIFY slug VARCHAR(191) NOT NULL');
    }

    public function down(): void
    {
        Schema::table('catalog_trading_partners', function (Blueprint $table) {
            if ($this->hasUniqueSlugIndex()) {
                $table->dropUnique(['slug']);
            }
            $table->dropColumn('slug');

            if (! $this->hasUniqueNameIndex()) {
                $table->unique('name');
            }
        });
    }

    private function hasUniqueNameIndex(): bool
    {
        foreach (Schema::getIndexes('catalog_trading_partners') as $index) {
            if (($index['name'] ?? '') === 'catalog_trading_partners_name_unique') {
                return true;
            }
            if ($index['columns'] === ['name'] && ($index['unique'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function hasNonUniqueNameIndex(): bool
    {
        foreach (Schema::getIndexes('catalog_trading_partners') as $index) {
            if ($index['columns'] === ['name'] && ! ($index['unique'] ?? false) && ! ($index['primary'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function hasUniqueSlugIndex(): bool
    {
        foreach (Schema::getIndexes('catalog_trading_partners') as $index) {
            if ($index['columns'] === ['slug'] && ($index['unique'] ?? false)) {
                return true;
            }
        }

        return false;
    }
};
