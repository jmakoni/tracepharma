<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TP-415: cold storage for aged epcis_events. Same event ids; no FK to hot events.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epcis_events_archive')) {
            Schema::create('epcis_events_archive', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('document_id')->nullable()->index();
                $table->unsignedInteger('ingest_generation')->nullable();
                $table->timestamp('superseded_at')->nullable();
                $table->unsignedInteger('superseded_by_generation')->nullable();
                $table->string('event_id', 128)->nullable();
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
                $table->unsignedBigInteger('trading_partner_id')->nullable();
                $table->json('extension_json')->nullable();
                $table->json('certification_info')->nullable();
                $table->json('sensor_element_list')->nullable();
                $table->timestamps();
                $table->timestamp('archived_at')->useCurrent();

                $table->index(['document_id', 'ingest_generation'], 'epcis_events_archive_doc_gen_idx');
            });
        }

        if (! Schema::hasTable('event_epcs_archive')) {
            Schema::create('event_epcs_archive', function (Blueprint $table): void {
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('epc_id')->index();
                $table->string('role', 16)->default('epcList');
                $table->decimal('quantity', 14, 4)->nullable();
                $table->string('uom', 10)->nullable();

                $table->unique(['event_id', 'epc_id', 'role'], 'event_epcs_archive_event_epc_role_uk');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_epcs_archive');
        Schema::dropIfExists('epcis_events_archive');
    }
};
