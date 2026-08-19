<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sscc_print_jobs', function (Blueprint $table): void {
            $table->string('delivery_mode', 16)->default('queue')->after('status');

            $table->index(['status', 'delivery_mode', 'queued_at'], 'sscc_print_jobs_status_delivery_queued_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sscc_print_jobs', function (Blueprint $table): void {
            $table->dropIndex('sscc_print_jobs_status_delivery_queued_idx');
            $table->dropColumn('delivery_mode');
        });
    }
};
