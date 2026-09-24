<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packing_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_kind', 32);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('status', 32)->default('open');
            $table->foreignId('parent_epc_id')->nullable()->constrained('epcs')->nullOnDelete();
            $table->foreignId('parent_label_id')->nullable()->constrained('sscc_labels')->nullOnDelete();
            $table->string('parent_sscc18', 18)->nullable();
            $table->unsignedInteger('staged_count')->default(0);
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('opened_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('packing_events_generated_at', 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index('status');
        });

        Schema::create('packing_scan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('packing_session_id')->constrained('packing_sessions')->cascadeOnDelete();
            $table->foreignId('epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->string('line_role', 16)->default('child');
            $table->string('status', 16)->default('staged');
            $table->string('scan_raw', 255)->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['packing_session_id', 'epc_id']);
            $table->index(['packing_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_scan_lines');
        Schema::dropIfExists('packing_sessions');
    }
};
