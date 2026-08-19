<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable()->after('preferences');
            }

            if (! Schema::hasColumn('users', 'terms_version')) {
                $table->string('terms_version')->nullable()->after('terms_accepted_at');
            }

            if (! Schema::hasColumn('users', 'privacy_accepted_at')) {
                $table->timestamp('privacy_accepted_at')->nullable()->after('terms_version');
            }

            if (! Schema::hasColumn('users', 'privacy_version')) {
                $table->string('privacy_version')->nullable()->after('privacy_accepted_at');
            }

            if (! Schema::hasColumn('users', 'legal_notice_started_at')) {
                $table->timestamp('legal_notice_started_at')->nullable()->after('privacy_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['legal_notice_started_at', 'privacy_version', 'privacy_accepted_at', 'terms_version', 'terms_accepted_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
