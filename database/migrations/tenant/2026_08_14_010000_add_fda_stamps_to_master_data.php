<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (! Schema::hasColumn('trading_partners', 'fda_organization_id')) {
                $table->unsignedBigInteger('fda_organization_id')->nullable()->after('catalog_trading_partner_id')->index();
            }
        });

        Schema::table('sites', function (Blueprint $table): void {
            if (! Schema::hasColumn('sites', 'fda_establishment_id')) {
                $table->unsignedBigInteger('fda_establishment_id')->nullable()->after('catalog_site_id')->index();
            }

            if (! Schema::hasColumn('sites', 'fda_wdd_facility_id')) {
                $table->unsignedBigInteger('fda_wdd_facility_id')->nullable()->after('fda_establishment_id')->index();
            }
        });

        Schema::table('atp_licenses', function (Blueprint $table): void {
            if (! Schema::hasColumn('atp_licenses', 'fda_wdd_license_id')) {
                $table->unsignedBigInteger('fda_wdd_license_id')->nullable()->after('catalog_atp_license_id')->index();
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'fda_product_packaging_id')) {
                $table->unsignedBigInteger('fda_product_packaging_id')->nullable()->after('fda_product_id')->index();
            }
        });
    }

    public function down(): void
    {
        $this->dropIfPresent('trading_partners', ['fda_organization_id']);
        $this->dropIfPresent('sites', ['fda_establishment_id', 'fda_wdd_facility_id']);
        $this->dropIfPresent('atp_licenses', ['fda_wdd_license_id']);
        $this->dropIfPresent('products', ['fda_product_packaging_id']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropIfPresent(string $table, array $columns): void
    {
        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        if ($present === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($present): void {
            $blueprint->dropColumn($present);
        });
    }
};
