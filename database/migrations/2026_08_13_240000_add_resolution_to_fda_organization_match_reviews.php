<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_organization_match_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('fda_organization_match_reviews', 'resolved_fda_organization_id')) {
                $table->unsignedBigInteger('resolved_fda_organization_id')->nullable()->after('proposed_fda_organization_id');
                $table->foreign('resolved_fda_organization_id', 'fda_org_match_reviews_resolved_org_fk')
                    ->references('id')
                    ->on('fda_organizations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('fda_organization_match_reviews', 'resolved_by_admin_id')) {
                $table->unsignedBigInteger('resolved_by_admin_id')->nullable()->after('resolved_fda_organization_id');
            }

            if (! Schema::hasColumn('fda_organization_match_reviews', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('resolved_by_admin_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fda_organization_match_reviews', function (Blueprint $table): void {
            if (Schema::hasColumn('fda_organization_match_reviews', 'resolved_fda_organization_id')) {
                $table->dropForeign('fda_org_match_reviews_resolved_org_fk');
            }
        });

        $columns = array_values(array_filter(
            ['resolved_fda_organization_id', 'resolved_by_admin_id', 'resolved_at'],
            static fn (string $column): bool => Schema::hasColumn('fda_organization_match_reviews', $column)
        ));

        if ($columns !== []) {
            Schema::table('fda_organization_match_reviews', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
