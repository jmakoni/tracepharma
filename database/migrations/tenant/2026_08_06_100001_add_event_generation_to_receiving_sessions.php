<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('receiving_sessions', 'receiving_epcis_document_id')) {
                $table->foreignId('receiving_epcis_document_id')
                    ->nullable()
                    ->after('epcis_document_id')
                    ->constrained('epcis_documents')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('receiving_sessions', 'receiving_events_generated_at')) {
                $table->dateTime('receiving_events_generated_at', 6)->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('receiving_sessions', 'receiving_events_generated_at')) {
                $table->dropColumn('receiving_events_generated_at');
            }

            if (Schema::hasColumn('receiving_sessions', 'receiving_epcis_document_id')) {
                $table->dropConstrainedForeignId('receiving_epcis_document_id');
            }
        });
    }
};
