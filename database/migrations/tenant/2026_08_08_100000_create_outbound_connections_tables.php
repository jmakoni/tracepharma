<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('serialization_provider');
            $table->string('transport');
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['transport', 'is_active']);
        });

        if (Schema::hasTable('epcis_documents')) {
            Schema::table('epcis_documents', function (Blueprint $table): void {
                if (! Schema::hasColumn('epcis_documents', 'outbound_connection_id')) {
                    $table->foreignId('outbound_connection_id')
                        ->nullable()
                        ->after('inbound_connection_id')
                        ->constrained('outbound_connections')
                        ->nullOnDelete();
                }

                if (! Schema::hasColumn('epcis_documents', 'sent_at')) {
                    $table->timestamp('sent_at')->nullable()->after('received_at');
                }

                if (! Schema::hasColumn('epcis_documents', 'transmission_status')) {
                    $table->string('transmission_status', 32)->nullable()->after('sent_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('epcis_documents')) {
            Schema::table('epcis_documents', function (Blueprint $table): void {
                if (Schema::hasColumn('epcis_documents', 'outbound_connection_id')) {
                    $table->dropForeign(['outbound_connection_id']);
                    $table->dropColumn('outbound_connection_id');
                }

                if (Schema::hasColumn('epcis_documents', 'sent_at')) {
                    $table->dropColumn('sent_at');
                }

                if (Schema::hasColumn('epcis_documents', 'transmission_status')) {
                    $table->dropColumn('transmission_status');
                }
            });
        }

        Schema::dropIfExists('outbound_connections');
    }
};
