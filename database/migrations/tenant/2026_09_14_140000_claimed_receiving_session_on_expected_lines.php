<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbound_expected_lines')) {
            return;
        }

        if (Schema::hasColumn('inbound_expected_lines', 'claimed_receiving_session_id')) {
            return;
        }

        Schema::table('inbound_expected_lines', function (Blueprint $table): void {
            $table->foreignId('claimed_receiving_session_id')
                ->nullable()
                ->constrained('receiving_sessions')
                ->nullOnDelete();
            $table->index('claimed_receiving_session_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inbound_expected_lines')) {
            return;
        }

        if (! Schema::hasColumn('inbound_expected_lines', 'claimed_receiving_session_id')) {
            return;
        }

        Schema::table('inbound_expected_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('claimed_receiving_session_id');
        });
    }
};
