<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fda_organization_match_reviews')) {
            return;
        }

        if (! Schema::hasColumn('fda_organization_match_reviews', 'duns_number')) {
            return;
        }

        Schema::table('fda_organization_match_reviews', function (Blueprint $table): void {
            $table->string('duns_number', 14)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fda_organization_match_reviews')) {
            return;
        }

        if (! Schema::hasColumn('fda_organization_match_reviews', 'duns_number')) {
            return;
        }

        $maxLen = (int) (DB::table('fda_organization_match_reviews')
            ->selectRaw('MAX(CHAR_LENGTH(duns_number)) as max_len')
            ->value('max_len') ?? 0);

        if ($maxLen > 9) {
            return;
        }

        Schema::table('fda_organization_match_reviews', function (Blueprint $table): void {
            $table->string('duns_number', 9)->nullable()->change();
        });
    }
};
