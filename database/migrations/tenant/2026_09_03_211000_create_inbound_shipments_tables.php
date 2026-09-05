<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbound_shipments')) {
            Schema::create('inbound_shipments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('trading_partner_id')
                    ->nullable()
                    ->constrained('trading_partners')
                    ->nullOnDelete();
                // 0 = unknown seller; avoids MySQL UNIQUE treating NULL as distinct.
                $table->unsignedBigInteger('trading_partner_key')->default(0);
                $table->string('asn_number', 255);
                $table->string('customer_po', 255)->nullable();
                $table->string('status', 32)->default('open');
                $table->unsignedInteger('document_count')->default(0);
                $table->timestamps();

                $table->unique(['trading_partner_key', 'asn_number'], 'inbound_shipments_partner_asn_unique');
                $table->index('asn_number');
            });
        }

        if (Schema::hasTable('epcis_documents') && ! Schema::hasColumn('epcis_documents', 'inbound_shipment_id')) {
            Schema::table('epcis_documents', function (Blueprint $table): void {
                $table->foreignId('inbound_shipment_id')
                    ->nullable()
                    ->after('trading_partner_id')
                    ->constrained('inbound_shipments')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('receiving_sessions') && ! Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
            Schema::table('receiving_sessions', function (Blueprint $table): void {
                $table->foreignId('inbound_shipment_id')
                    ->nullable()
                    ->after('epcis_document_id')
                    ->constrained('inbound_shipments')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('receiving_sessions') && Schema::hasColumn('receiving_sessions', 'inbound_shipment_id')) {
            Schema::table('receiving_sessions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('inbound_shipment_id');
            });
        }

        if (Schema::hasTable('epcis_documents') && Schema::hasColumn('epcis_documents', 'inbound_shipment_id')) {
            Schema::table('epcis_documents', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('inbound_shipment_id');
            });
        }

        Schema::dropIfExists('inbound_shipments');
    }
};
