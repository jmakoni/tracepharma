<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferring_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_site_id')->constrained('sites');
            $table->foreignId('to_site_id')->constrained('sites');
            $table->string('status', 32)->default('open');
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('opened_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->foreignId('transfer_epcis_document_id')
                ->nullable()
                ->constrained('epcis_documents')
                ->nullOnDelete();
            $table->dateTime('transfer_events_generated_at', 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('transferring_scan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transferring_session_id')->constrained('transferring_sessions')->cascadeOnDelete();
            $table->foreignId('epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->string('status', 16)->default('confirmed');
            $table->string('scan_raw', 255)->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['transferring_session_id', 'epc_id']);
            $table->index(['transferring_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferring_scan_lines');
        Schema::dropIfExists('transferring_sessions');
    }
};
