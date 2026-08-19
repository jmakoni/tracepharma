<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_shipping_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites');
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->foreignId('ship_to_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('ship_to_gln', 13)->nullable();
            $table->foreignId('outbound_connection_id')->nullable()->constrained('outbound_connections')->nullOnDelete();
            $table->string('status', 32)->default('open');
            $table->string('asn_number')->nullable();
            $table->string('customer_po')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('shipment_reference')->nullable();
            $table->boolean('dscsa_affirm')->default(false);
            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->foreignId('epcis_document_id')->nullable()->constrained('epcis_documents')->nullOnDelete();
            $table->dateTime('shipping_events_generated_at', 6)->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('opened_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('site_id');
            $table->index('trading_partner_id');
        });

        Schema::create('outbound_shipping_scan_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outbound_shipping_session_id');
            $table->foreignId('epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->string('line_role', 16)->default('parent');
            $table->string('status', 16)->default('confirmed');
            $table->string('scan_raw', 255)->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('outbound_shipping_session_id', 'oss_scan_lines_session_fk')
                ->references('id')
                ->on('outbound_shipping_sessions')
                ->cascadeOnDelete();

            $table->unique(['outbound_shipping_session_id', 'epc_id'], 'oss_scan_lines_session_epc_unique');
            $table->index(['outbound_shipping_session_id', 'status'], 'oss_scan_lines_session_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_shipping_scan_lines');
        Schema::dropIfExists('outbound_shipping_sessions');
    }
};
