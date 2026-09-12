<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('verifications', 'verification_request_case_id')) {
            Schema::table('verifications', function (Blueprint $table): void {
                $table->foreignId('verification_request_case_id')
                    ->nullable()
                    ->after('exception_id')
                    ->constrained('verification_request_cases')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('verifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verification_request_case_id');
        });
    }
};
