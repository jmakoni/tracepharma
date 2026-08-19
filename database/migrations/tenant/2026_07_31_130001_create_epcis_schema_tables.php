<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epcis_documents', function (Blueprint $table) {
            $table->id();
            $table->char('document_uuid', 36)->unique();
            $table->string('schema_version', 10)->default('1.2');
            $table->dateTime('creation_date', 6)->index();
            $table->string('direction', 16);
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->unsignedBigInteger('inbound_connection_id')->nullable()->index();
            $table->string('format', 8)->default('xml');
            $table->string('original_filename', 512)->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->string('payload_disk', 64)->nullable();
            $table->string('payload_path', 1024)->nullable();
            $table->boolean('dscsa_affirm')->default(true);
            $table->text('legal_notice')->nullable();
            $table->string('status', 32)->default('received');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('epc_count')->default(0);
            $table->dateTime('received_at', 6);
            $table->dateTime('processed_at', 6)->nullable();
            $table->timestamps();

            $table->index(['direction', 'status']);
        });

        Schema::create('epcis_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('epcis_documents')->cascadeOnDelete();
            $table->string('event_id', 128)->nullable()->unique();
            $table->string('event_type', 32);
            $table->dateTime('event_time', 6)->index();
            $table->string('event_timezone_offset', 6)->nullable();
            $table->dateTime('record_time', 6)->nullable();
            $table->string('action', 16)->default('ADD');
            $table->string('biz_step')->nullable();
            $table->string('disposition')->nullable();
            $table->string('persistent_disposition')->nullable();
            $table->json('error_declaration')->nullable();
            $table->json('corrective_event_ids')->nullable();
            $table->char('read_point_gln', 13)->nullable();
            $table->char('biz_location_gln', 13)->nullable();
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->json('extension_json')->nullable();
            $table->json('certification_info')->nullable();
            $table->json('sensor_element_list')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'action']);
            $table->index('biz_step');
            $table->index('disposition');
        });

        Schema::create('epcs', function (Blueprint $table) {
            $table->id();
            $table->string('epc_uri', 128)->unique();
            $table->string('epc_type', 16);
            $table->string('company_prefix', 12);
            $table->unsignedTinyInteger('indicator_digit')->nullable();
            $table->string('item_reference', 13)->nullable();
            $table->string('serial_number', 40)->nullable()->comment('never strip leading zeros');
            $table->unsignedTinyInteger('extension_digit')->nullable();
            $table->char('gtin14', 14)->nullable();
            $table->char('sscc18', 18)->nullable()->unique();
            $table->string('ai_01_21', 64)->nullable()->index();
            $table->string('ai_00', 32)->nullable()->index();
            $table->string('digital_link', 256)->nullable();
            $table->string('packaging_level', 16)->nullable();
            $table->string('packaging_type', 50)->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->dateTime('first_seen_at', 6)->nullable();
            $table->unsignedBigInteger('last_event_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['gtin14', 'serial_number'], 'uk_gtin_serial');
            $table->index('serial_number');
            $table->index(['company_prefix', 'serial_number']);
            $table->index(['packaging_level', 'packaging_type']);
        });

        Schema::create('event_epcs', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('epcis_events')->cascadeOnDelete();
            $table->foreignId('epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->string('role', 16)->default('epcList');
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('uom', 10)->nullable();

            $table->primary(['event_id', 'epc_id', 'role']);
        });

        Schema::create('aggregation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->foreignId('child_epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->foreignId('established_by_event_id')->constrained('epcis_events')->cascadeOnDelete();
            $table->string('link_type', 16)->default('aggregation');
            $table->dateTime('valid_from', 6);
            $table->dateTime('valid_to', 6)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->unique(['parent_epc_id', 'child_epc_id', 'valid_from']);
        });

        Schema::create('event_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('epcis_events')->cascadeOnDelete();
            $table->string('party_role', 16);
            $table->char('gln', 13)->nullable()->index();
            $table->string('gln_uri', 80)->nullable();
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->json('extra_json')->nullable();
        });

        Schema::create('event_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('epcis_events')->cascadeOnDelete();
            $table->string('location_type', 32);
            $table->char('gln', 13)->nullable();
            $table->string('gln_uri', 80)->nullable();
            $table->string('name')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('location_device_id')->nullable()->constrained('location_devices')->nullOnDelete();
            $table->foreignId('read_point_id')->nullable()->constrained('read_points')->nullOnDelete();
            $table->json('extra_json')->nullable();

            $table->index(['event_id', 'location_type']);
            $table->index('gln');
        });

        Schema::create('event_biz_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('epcis_events')->cascadeOnDelete();
            $table->string('type_uri', 128);
            $table->string('value', 128);

            $table->index('value');
        });

        Schema::create('epc_ilmd', function (Blueprint $table) {
            $table->foreignId('epc_id')->primary()->constrained('epcs')->cascadeOnDelete();
            $table->string('lot_number', 20)->nullable()->index();
            $table->date('expiry_date')->nullable()->index();
            $table->date('manufacturing_date')->nullable();
            $table->date('best_before_date')->nullable();
            $table->string('additional_id', 64)->nullable();
            $table->json('extra_json')->nullable();
        });

        Schema::create('transmission_mdns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('epcis_documents')->cascadeOnDelete();
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->string('mdn_status', 16)->default('pending');
            $table->dateTime('mdn_received_at', 6)->nullable();
            $table->json('mdn_payload')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
        });

        Schema::create('epcis_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained('epcis_documents')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('epcis_events')->nullOnDelete();
            $table->foreignId('epc_id')->nullable()->constrained('epcs')->nullOnDelete();
            $table->string('exception_type', 64);
            $table->string('severity', 16)->default('error');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('assigned_to', 100)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['status', 'exception_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epcis_exceptions');
        Schema::dropIfExists('transmission_mdns');
        Schema::dropIfExists('epc_ilmd');
        Schema::dropIfExists('event_biz_transactions');
        Schema::dropIfExists('event_locations');
        Schema::dropIfExists('event_parties');
        Schema::dropIfExists('aggregation_links');
        Schema::dropIfExists('event_epcs');
        Schema::dropIfExists('epcs');
        Schema::dropIfExists('epcis_events');
        Schema::dropIfExists('epcis_documents');
    }
};
