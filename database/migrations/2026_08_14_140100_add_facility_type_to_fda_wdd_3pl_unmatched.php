<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The FDA Type of the skipped rows, so triage can propose 3PL vs Wholesaler
     * without opening the source report.
     */
    public function up(): void
    {
        Schema::table('fda_wdd_3pl_unmatched', function (Blueprint $table): void {
            if (! Schema::hasColumn('fda_wdd_3pl_unmatched', 'facility_type')) {
                $table->string('facility_type', 8)->nullable()->after('slug_attempt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fda_wdd_3pl_unmatched', function (Blueprint $table): void {
            if (Schema::hasColumn('fda_wdd_3pl_unmatched', 'facility_type')) {
                $table->dropColumn('facility_type');
            }
        });
    }
};
