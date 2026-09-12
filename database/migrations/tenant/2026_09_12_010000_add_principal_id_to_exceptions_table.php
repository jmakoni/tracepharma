<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exceptions', function (Blueprint $table): void {
            $table->foreignId('principal_id')
                ->nullable()
                ->after('site_id')
                ->constrained('principals')
                ->nullOnDelete();

            $table->index('principal_id');
        });
    }

    public function down(): void
    {
        Schema::table('exceptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('principal_id');
        });
    }
};
