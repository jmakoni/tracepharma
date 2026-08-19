<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_products', function (Blueprint $table) {
            if (! Schema::hasColumn('fda_products', 'fda_organization_id')) {
                $table->foreignId('fda_organization_id')
                    ->nullable()
                    ->after('catalog_trading_partner_id')
                    ->constrained('fda_organizations')
                    ->nullOnDelete();
            }
        });

        Schema::table('catalog_trading_partners', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_trading_partners', 'fda_organization_id')) {
                $table->foreignId('fda_organization_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('fda_organizations')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('catalog_trading_partners', 'canonical_name')) {
                $table->string('canonical_name')->nullable()->after('slug');
                $table->index('canonical_name');
            }
            if (! Schema::hasColumn('catalog_trading_partners', 'full_address')) {
                $table->text('full_address')->nullable()->after('country_code');
            }
        });

        Schema::table('catalog_sites', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_sites', 'fda_establishment_id')) {
                $table->foreignId('fda_establishment_id')
                    ->nullable()
                    ->after('catalog_trading_partner_id')
                    ->constrained('fda_establishments')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('catalog_sites', 'fda_wdd_facility_id')) {
                $table->foreignId('fda_wdd_facility_id')
                    ->nullable()
                    ->after('fda_establishment_id')
                    ->constrained('fda_wdd_facilities')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('catalog_sites', 'full_address')) {
                $table->text('full_address')->nullable()->after('country_code');
            }
        });

        Schema::table('catalog_atp_licenses', function (Blueprint $table) {
            if (! Schema::hasColumn('catalog_atp_licenses', 'fda_wdd_license_id')) {
                $table->foreignId('fda_wdd_license_id')
                    ->nullable()
                    ->after('catalog_site_id')
                    ->constrained('fda_wdd_licenses')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('catalog_atp_licenses', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_atp_licenses', 'fda_wdd_license_id')) {
                $table->dropConstrainedForeignId('fda_wdd_license_id');
            }
        });

        Schema::table('catalog_sites', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_sites', 'fda_establishment_id')) {
                $table->dropConstrainedForeignId('fda_establishment_id');
            }
            if (Schema::hasColumn('catalog_sites', 'fda_wdd_facility_id')) {
                $table->dropConstrainedForeignId('fda_wdd_facility_id');
            }
            if (Schema::hasColumn('catalog_sites', 'full_address')) {
                $table->dropColumn('full_address');
            }
        });

        Schema::table('catalog_trading_partners', function (Blueprint $table) {
            if (Schema::hasColumn('catalog_trading_partners', 'fda_organization_id')) {
                $table->dropConstrainedForeignId('fda_organization_id');
            }
            if (Schema::hasColumn('catalog_trading_partners', 'canonical_name')) {
                $table->dropIndex(['canonical_name']);
                $table->dropColumn('canonical_name');
            }
            if (Schema::hasColumn('catalog_trading_partners', 'full_address')) {
                $table->dropColumn('full_address');
            }
        });

        Schema::table('fda_products', function (Blueprint $table) {
            if (Schema::hasColumn('fda_products', 'fda_organization_id')) {
                $table->dropConstrainedForeignId('fda_organization_id');
            }
        });
    }
};
