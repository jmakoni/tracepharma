<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disposition_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('biz_step', 64);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('status', 32)->default('open');
            $table->unsignedInteger('staged_count')->default(0);
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('opened_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('disposition_events_generated_at', 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index('status');
        });

        Schema::create('disposition_scan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disposition_session_id')->constrained('disposition_sessions')->cascadeOnDelete();
            $table->foreignId('epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->string('status', 16)->default('staged');
            $table->string('scan_raw', 255)->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['disposition_session_id', 'epc_id']);
            $table->index(['disposition_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposition_scan_lines');
        Schema::dropIfExists('disposition_sessions');
    }
};
