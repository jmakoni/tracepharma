<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_wdd_3pl_staging', function (Blueprint $table): void {
            if (! Schema::hasColumn('fda_wdd_3pl_staging', 'fda_organization_id')) {
                $table->unsignedBigInteger('fda_organization_id')->nullable()->after('catalog_trading_partner_id');
                $table->foreign('fda_organization_id', 'fda_wdd_3pl_staging_fda_org_fk')
                    ->references('id')
                    ->on('fda_organizations')
                    ->nullOnDelete();
            }
        });

        Schema::table('fda_wdd_3pl_unmatched', function (Blueprint $table): void {
            if (! Schema::hasColumn('fda_wdd_3pl_unmatched', 'fda_organization_id')) {
                $table->unsignedBigInteger('fda_organization_id')->nullable()->after('catalog_trading_partner_id');
                $table->foreign('fda_organization_id', 'fda_wdd_3pl_unmatched_fda_org_fk')
                    ->references('id')
                    ->on('fda_organizations')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fda_wdd_3pl_staging', function (Blueprint $table): void {
            if (Schema::hasColumn('fda_wdd_3pl_staging', 'fda_organization_id')) {
                $table->dropForeign('fda_wdd_3pl_staging_fda_org_fk');
                $table->dropColumn('fda_organization_id');
            }
        });

        Schema::table('fda_wdd_3pl_unmatched', function (Blueprint $table): void {
            if (Schema::hasColumn('fda_wdd_3pl_unmatched', 'fda_organization_id')) {
                $table->dropForeign('fda_wdd_3pl_unmatched_fda_org_fk');
                $table->dropColumn('fda_organization_id');
            }
        });
    }
};
