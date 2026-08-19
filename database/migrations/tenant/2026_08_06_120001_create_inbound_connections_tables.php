<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('serialization_provider');
            $table->string('transport');
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->uuid('inbound_token');
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['transport', 'is_active']);
            $table->unique('inbound_token');
        });

        Schema::create('inbound_connection_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inbound_connection_id')->constrained('inbound_connections')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('status', 32);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['inbound_connection_id', 'created_at']);
        });

        Schema::create('inbound_connection_trading_partner', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inbound_connection_id')->constrained('inbound_connections')->cascadeOnDelete();
            $table->foreignId('trading_partner_id')->constrained('trading_partners')->cascadeOnDelete();
            $table->string('sender_gln', 13)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();

            $table->unique(
                ['inbound_connection_id', 'trading_partner_id'],
                'inbound_conn_trading_partner_unique',
            );
            $table->index(['inbound_connection_id', 'sender_gln'], 'inbound_conn_sender_gln_index');
        });

        if (Schema::hasTable('epcis_documents') && Schema::hasColumn('epcis_documents', 'inbound_connection_id')) {
            Schema::table('epcis_documents', function (Blueprint $table): void {
                $table->foreign('inbound_connection_id')
                    ->references('id')
                    ->on('inbound_connections')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('epcis_documents') && Schema::hasColumn('epcis_documents', 'inbound_connection_id')) {
            Schema::table('epcis_documents', function (Blueprint $table): void {
                $table->dropForeign(['inbound_connection_id']);
            });
        }

        Schema::dropIfExists('inbound_connection_trading_partner');
        Schema::dropIfExists('inbound_connection_logs');
        Schema::dropIfExists('inbound_connections');
    }
};
