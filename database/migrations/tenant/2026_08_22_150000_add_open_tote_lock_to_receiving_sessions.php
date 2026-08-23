<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('receiving_sessions', 'active_parent_epc_id')) {
                $table->foreignId('active_parent_epc_id')
                    ->nullable()
                    ->after('confirmed_child_count')
                    ->constrained('epcs')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('receiving_sessions', 'short_closed_parent_epc_ids')) {
                $table->json('short_closed_parent_epc_ids')->nullable()->after('active_parent_epc_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('receiving_sessions', 'short_closed_parent_epc_ids')) {
                $table->dropColumn('short_closed_parent_epc_ids');
            }

            if (Schema::hasColumn('receiving_sessions', 'active_parent_epc_id')) {
                $table->dropConstrainedForeignId('active_parent_epc_id');
            }
        });
    }
};
