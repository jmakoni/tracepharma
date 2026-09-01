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
        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_issuer')->nullable()->after('email');
            $table->string('oidc_subject')->nullable()->after('oidc_issuer');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['oidc_issuer', 'oidc_subject'], 'users_oidc_issuer_subject_unique');
        });

        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NULL');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_oidc_issuer_subject_unique');
            $table->dropColumn(['oidc_issuer', 'oidc_subject']);
        });

        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL');
    }
};
