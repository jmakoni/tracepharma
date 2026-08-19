<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receiving_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epcis_document_id')->constrained('epcis_documents')->cascadeOnDelete()->unique();
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('status', 32)->default('open');
            $table->unsignedInteger('expected_parent_count')->default(0);
            $table->unsignedInteger('confirmed_parent_count')->default(0);
            $table->unsignedInteger('expected_child_count')->default(0);
            $table->unsignedInteger('confirmed_child_count')->default(0);
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('opened_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('receiving_scan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receiving_session_id')->constrained('receiving_sessions')->cascadeOnDelete();
            $table->foreignId('epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->foreignId('parent_epc_id')->nullable()->constrained('epcs')->nullOnDelete();
            $table->string('line_role', 16);
            $table->string('status', 16)->default('expected');
            $table->string('scan_raw', 255)->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('ilmd_mismatch_json')->nullable();
            $table->timestamps();

            $table->unique(['receiving_session_id', 'epc_id']);
            $table->index(['receiving_session_id', 'status']);
            $table->index(['receiving_session_id', 'parent_epc_id', 'status'], 'receiving_scan_lines_session_parent_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_scan_lines');
        Schema::dropIfExists('receiving_sessions');
    }
};
