<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epcis_documents')) {
            return;
        }

        if (Schema::hasColumn('epcis_documents', 'corrects_epcis_document_id')) {
            return;
        }

        // A corrective shipment amends an earlier authored one. The link lived in the
        // notes prose; auditors and the corrective gate both need it as data.
        Schema::table('epcis_documents', function (Blueprint $table): void {
            $table->foreignId('corrects_epcis_document_id')
                ->nullable()
                ->after('authored_kind')
                ->constrained('epcis_documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('epcis_documents')
            || ! Schema::hasColumn('epcis_documents', 'corrects_epcis_document_id')
        ) {
            return;
        }

        Schema::table('epcis_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corrects_epcis_document_id');
        });
    }
};
