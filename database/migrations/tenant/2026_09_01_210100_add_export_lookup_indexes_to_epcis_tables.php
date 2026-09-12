<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_epcs')) {
            Schema::table('document_epcs', function (Blueprint $table): void {
                $table->index(['epc_id', 'ingest_generation'], 'document_epcs_epc_id_ingest_generation_index');
            });
        }

        if (Schema::hasTable('event_epcs')) {
            Schema::table('event_epcs', function (Blueprint $table): void {
                $table->index(['epc_id', 'event_id'], 'event_epcs_epc_id_event_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('document_epcs')) {
            Schema::table('document_epcs', function (Blueprint $table): void {
                $table->dropIndex('document_epcs_epc_id_ingest_generation_index');
            });
        }

        if (Schema::hasTable('event_epcs')) {
            Schema::table('event_epcs', function (Blueprint $table): void {
                $table->dropIndex('event_epcs_epc_id_event_id_index');
            });
        }
    }
};
