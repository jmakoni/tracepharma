<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epcis_jobs', function (Blueprint $table): void {
            $table->id();
            $table->char('receipt', 32)->unique();
            $table->string('kind', 64);
            $table->string('status', 32);
            $table->foreignId('epcis_document_id')->nullable()->constrained('epcis_documents')->nullOnDelete();
            $table->foreignId('outbound_shipping_session_id')->nullable()->constrained('outbound_shipping_sessions')->nullOnDelete();
            $table->foreignId('receiving_session_id')->nullable()->constrained('receiving_sessions')->nullOnDelete();
            $table->foreignId('transferring_session_id')->nullable()->constrained('transferring_sessions')->nullOnDelete();
            $table->foreignId('sscc_label_batch_id')->nullable()->constrained('sscc_label_batches')->nullOnDelete();
            $table->foreignId('outbound_connection_id')->nullable()->constrained('outbound_connections')->nullOnDelete();
            $table->foreignId('ship_from_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename')->nullable();
            $table->timestamp('received_at', 6);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('finished_at', 6)->nullable();
            $table->timestamp('archived_at', 6)->nullable();
            $table->unsignedInteger('processing_time_ms')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('error_message')->nullable();
            $table->json('stats_json')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['kind', 'status']);
            $table->index('archived_at');
        });

        Schema::create('epcis_job_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('epcis_job_id')->constrained('epcis_jobs')->cascadeOnDelete();
            $table->string('level', 16);
            $table->text('message');
            $table->timestamp('created_at', 6);

            $table->index(['epcis_job_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epcis_job_messages');
        Schema::dropIfExists('epcis_jobs');
    }
};
