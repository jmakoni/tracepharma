<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('receiving_sessions', 'session_kind')) {
                $table->string('session_kind', 32)
                    ->default('inbound_asn')
                    ->after('id');
            }
        });

        // Drop FK so we can relax NOT NULL; unique index stays (MySQL allows multiple NULLs).
        Schema::table('receiving_sessions', function (Blueprint $table) {
            $table->dropForeign(['epcis_document_id']);
        });

        Schema::table('receiving_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('epcis_document_id')->nullable()->change();
        });

        Schema::table('receiving_sessions', function (Blueprint $table) {
            $table->foreign('epcis_document_id')
                ->references('id')
                ->on('epcis_documents')
                ->cascadeOnDelete();
        });

        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('receiving_sessions', 'transferring_session_id')) {
                $table->foreignId('transferring_session_id')
                    ->nullable()
                    ->after('epcis_document_id')
                    ->unique()
                    ->constrained('transferring_sessions')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('receiving_sessions', 'matched_epcis_document_id')) {
                $table->foreignId('matched_epcis_document_id')
                    ->nullable()
                    ->after('receiving_epcis_document_id')
                    ->constrained('epcis_documents')
                    ->nullOnDelete();
            }

            $table->index(['status', 'session_kind']);
        });
    }

    public function down(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'session_kind']);
        });

        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('receiving_sessions', 'matched_epcis_document_id')) {
                $table->dropConstrainedForeignId('matched_epcis_document_id');
            }

            if (Schema::hasColumn('receiving_sessions', 'transferring_session_id')) {
                $table->dropConstrainedForeignId('transferring_session_id');
            }
        });

        Schema::table('receiving_sessions', function (Blueprint $table) {
            $table->dropForeign(['epcis_document_id']);
        });

        // Existing rows should have a document for ASN sessions; null rows block restoring NOT NULL.
        Schema::table('receiving_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('epcis_document_id')->nullable(false)->change();
        });

        Schema::table('receiving_sessions', function (Blueprint $table) {
            $table->foreign('epcis_document_id')
                ->references('id')
                ->on('epcis_documents')
                ->cascadeOnDelete();
        });

        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('receiving_sessions', 'session_kind')) {
                $table->dropColumn('session_kind');
            }
        });
    }
};
