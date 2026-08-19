<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_printers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->unsignedSmallInteger('port')->default(9100);
            $table->string('protocol')->default('zpl_raw');
            $table->boolean('is_default')->default(false);
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('sscc_serial_pools', function (Blueprint $table): void {
            $table->id();
            $table->string('company_prefix');
            $table->string('extension_digit', 1);
            $table->string('default_allocation_mode')->default('sequential');
            $table->unsignedBigInteger('last_serial_reference_int')->default(0);
            $table->unsignedBigInteger('last_printed_serial_reference_int')->nullable();
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_prefix', 'extension_digit'], 'sscc_serial_pools_unique');
        });

        Schema::create('sscc_label_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('company_prefix');
            $table->string('extension_digit', 1);
            $table->string('allocation_mode');
            $table->unsignedInteger('label_count');
            $table->unsignedInteger('copies_per_label')->default(1);
            $table->json('allocation_config')->nullable();
            $table->string('status');
            $table->string('ship_to_name')->nullable();
            $table->string('ship_to_gln')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('label_printer_id')->nullable()->constrained('label_printers')->nullOnDelete();
            $table->boolean('send_to_printer')->default(false);
            $table->boolean('emit_epcis')->default(false);
            $table->boolean('emit_disaggregation')->default(false);
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->unsignedBigInteger('source_epcis_document_id')->nullable();
            $table->string('source_parent_sscc_urn')->nullable();
            $table->string('epcis_file_path')->nullable();
            $table->timestamp('epcis_emitted_at')->nullable();
            $table->string('commissioning_epcis_file_path')->nullable();
            $table->timestamp('commissioned_at')->nullable();
            $table->string('disaggregation_file_path')->nullable();
            $table->timestamp('disaggregation_emitted_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('source_epcis_document_id');
        });

        Schema::create('sscc_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->nullable()->constrained('sscc_label_batches')->nullOnDelete();
            $table->foreignId('label_printer_id')->nullable()->constrained('label_printers')->nullOnDelete();
            $table->string('sscc_18');
            $table->string('sscc_urn');
            $table->string('extension_digit', 1);
            $table->string('company_prefix');
            $table->string('serial_reference');
            $table->unsignedBigInteger('serial_reference_int');
            $table->string('allocation_mode')->nullable();
            $table->string('element_string');
            $table->string('hrt');
            $table->string('ship_to_name')->nullable();
            $table->string('ship_to_gln')->nullable();
            $table->text('notes')->nullable();
            $table->string('label_disk');
            $table->string('label_path');
            $table->string('template_version', 32)->nullable();
            $table->string('print_status')->default('pending');
            $table->unsignedSmallInteger('printed_copies')->default(0);
            $table->timestamp('printed_at')->nullable();
            $table->string('epcis_file_path')->nullable();
            $table->timestamp('epcis_emitted_at')->nullable();
            $table->string('commissioning_epcis_file_path')->nullable();
            $table->timestamp('commissioned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('sscc_18');
            $table->unique('sscc_urn');
            $table->unique(
                ['company_prefix', 'extension_digit', 'serial_reference_int'],
                'sscc_labels_serial_unique',
            );
            $table->index('created_at');
        });

        Schema::create('sscc_label_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sscc_label_id')->constrained('sscc_labels')->cascadeOnDelete();
            $table->string('child_epc');
            $table->timestamps();

            $table->unique(['sscc_label_id', 'child_epc']);
        });

        Schema::create('sscc_print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sscc_label_batch_id')->nullable()->constrained('sscc_label_batches')->nullOnDelete();
            $table->foreignId('sscc_label_id')->constrained('sscc_labels')->cascadeOnDelete();
            $table->foreignId('label_printer_id')->constrained('label_printers')->cascadeOnDelete();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->string('status');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sscc_print_jobs');
        Schema::dropIfExists('sscc_label_children');
        Schema::dropIfExists('sscc_labels');
        Schema::dropIfExists('sscc_label_batches');
        Schema::dropIfExists('sscc_serial_pools');
        Schema::dropIfExists('label_printers');
    }
};
