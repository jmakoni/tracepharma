<?php

use App\Actions\Fda\DedupeFdaOrganizationMatchReviews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'fda_org_match_reviews_dedupe_uq';

    public function up(): void
    {
        if (! Schema::hasTable('fda_organization_match_reviews')) {
            return;
        }

        app(DedupeFdaOrganizationMatchReviews::class)->handle(null, false);

        if (! Schema::hasColumn('fda_organization_match_reviews', 'proposed_org_key')) {
            Schema::table('fda_organization_match_reviews', function (Blueprint $table): void {
                // VIRTUAL: MariaDB rejects STORED generated columns that depend on
                // FK columns with ON DELETE SET NULL (error 1901).
                $table->unsignedBigInteger('proposed_org_key')
                    ->virtualAs('COALESCE(proposed_fda_organization_id, 0)');
            });
        }

        if (! Schema::hasIndex('fda_organization_match_reviews', self::INDEX)) {
            Schema::table('fda_organization_match_reviews', function (Blueprint $table): void {
                $table->unique(
                    ['source', 'original_name', 'proposed_org_key', 'status'],
                    self::INDEX
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fda_organization_match_reviews')) {
            return;
        }

        if (Schema::hasIndex('fda_organization_match_reviews', self::INDEX)) {
            Schema::table('fda_organization_match_reviews', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn('fda_organization_match_reviews', 'proposed_org_key')) {
            Schema::table('fda_organization_match_reviews', function (Blueprint $table): void {
                $table->dropColumn('proposed_org_key');
            });
        }
    }
};
