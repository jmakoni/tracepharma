<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transferring_sessions', function (Blueprint $table) {
            $table->unsignedInteger('received_count')->default(0)->after('confirmed_count');
            $table->dateTime('shipped_at', 6)->nullable()->after('opened_at');
            $table->dateTime('received_at', 6)->nullable()->after('shipped_at');
            $table->dateTime('receive_events_generated_at', 6)
                ->nullable()
                ->after('transfer_events_generated_at');
        });

        Schema::table('transferring_scan_lines', function (Blueprint $table) {
            $table->dateTime('received_at', 6)->nullable()->after('confirmed_by');
            $table->foreignId('received_by')
                ->nullable()
                ->after('received_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transferring_scan_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn('received_at');
        });

        Schema::table('transferring_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'received_count',
                'shipped_at',
                'received_at',
                'receive_events_generated_at',
            ]);
        });
    }
};
