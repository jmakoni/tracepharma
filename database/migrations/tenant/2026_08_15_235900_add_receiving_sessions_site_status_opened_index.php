<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table): void {
            $table->index(['site_id', 'status', 'opened_at'], 'receiving_sessions_site_status_opened_idx');
        });
    }

    public function down(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table): void {
            $table->dropIndex('receiving_sessions_site_status_opened_idx');
        });
    }
};
