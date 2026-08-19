<?php

use App\Support\PartnerSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'trading_partner_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('trading_partner_id')->nullable()->after('fda_product_id')
                    ->constrained('trading_partners')->nullOnDelete();
            });
        }

        $this->backfillBySlug();
        $this->backfillFromCatalogTradingPartner();

        if (Schema::hasColumn('products', 'manufacturer_name')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('manufacturer_name');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'manufacturer_name')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('manufacturer_name')->nullable()->after('strength');
            });
        }

        if (Schema::hasColumn('products', 'trading_partner_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropConstrainedForeignId('trading_partner_id');
            });
        }
    }

    /**
     * Match products.manufacturer_name to trading_partners.name via the
     * shared manufacturer slug (trading_partners has no persisted slug).
     */
    private function backfillBySlug(): void
    {
        if (! Schema::hasColumn('products', 'manufacturer_name')) {
            return;
        }

        $partners = DB::table('trading_partners')->select('id', 'name')->get();

        if ($partners->isEmpty()) {
            return;
        }

        $partnerIdsBySlug = [];

        foreach ($partners as $partner) {
            $partnerIdsBySlug[PartnerSlug::from((string) $partner->name)] = (int) $partner->id;
        }

        DB::table('products')
            ->whereNull('trading_partner_id')
            ->whereNotNull('manufacturer_name')
            ->where('manufacturer_name', '!=', '')
            ->select('id', 'manufacturer_name')
            ->chunkById(1000, function ($rows) use ($partnerIdsBySlug) {
                foreach ($rows as $row) {
                    $slug = PartnerSlug::from((string) $row->manufacturer_name);
                    $partnerId = $partnerIdsBySlug[$slug] ?? null;

                    if ($partnerId !== null) {
                        DB::table('products')->where('id', $row->id)->update(['trading_partner_id' => $partnerId]);
                    }
                }
            });
    }

    /**
     * Fallback: a product linked to a central catalog product can resolve
     * its manufacturer through trading_partners.catalog_trading_partner_id,
     * which mirrors the central catalog_products.catalog_trading_partner_id.
     */
    private function backfillFromCatalogTradingPartner(): void
    {
        $central = $this->centralConnection();

        if (! Schema::hasColumn('products', 'catalog_product_id')
            || ! Schema::hasColumn('trading_partners', 'catalog_trading_partner_id')
            || ! Schema::connection($central)->hasTable('catalog_products')
        ) {
            return;
        }

        $productCatalogIds = DB::table('products')
            ->whereNull('trading_partner_id')
            ->whereNotNull('catalog_product_id')
            ->pluck('catalog_product_id', 'id');

        if ($productCatalogIds->isEmpty()) {
            return;
        }

        $catalogTradingPartnerIdByCatalogProductId = DB::connection($central)
            ->table('catalog_products')
            ->whereIn('id', $productCatalogIds->unique()->values())
            ->whereNotNull('catalog_trading_partner_id')
            ->pluck('catalog_trading_partner_id', 'id');

        if ($catalogTradingPartnerIdByCatalogProductId->isEmpty()) {
            return;
        }

        $tradingPartnerIdByCatalogTradingPartnerId = DB::table('trading_partners')
            ->whereNotNull('catalog_trading_partner_id')
            ->pluck('id', 'catalog_trading_partner_id');

        foreach ($productCatalogIds as $productId => $catalogProductId) {
            $catalogTradingPartnerId = $catalogTradingPartnerIdByCatalogProductId[$catalogProductId] ?? null;

            if ($catalogTradingPartnerId === null) {
                continue;
            }

            $tradingPartnerId = $tradingPartnerIdByCatalogTradingPartnerId[$catalogTradingPartnerId] ?? null;

            if ($tradingPartnerId === null) {
                continue;
            }

            DB::table('products')->where('id', $productId)->update(['trading_partner_id' => $tradingPartnerId]);
        }
    }

    private function centralConnection(): string
    {
        return config('tenancy.database.central_connection', config('database.default'));
    }
};
