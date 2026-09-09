<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atp_credentials', function (Blueprint $table): void {
            $table->string('verification_status', 32)->default('skipped')->after('header_sha256');
            $table->string('verification_reason')->nullable()->after('verification_status');
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('atp_credentials', function (Blueprint $table): void {
            $table->dropIndex(['verification_status']);
            $table->dropColumn(['verification_status', 'verification_reason']);
        });
    }
};
