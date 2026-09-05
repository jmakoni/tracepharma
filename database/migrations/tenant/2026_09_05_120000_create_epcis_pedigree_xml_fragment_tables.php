<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lossless pedigree XML fragments for outbound TI rebuild when payload files are gone.
 * Stores commissioning/packing event outer XML and Location/EPCClass VocabularyElement XML
 * as ingested — never re-authored from normalized columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epcis_pedigree_event_fragments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('ingest_generation')->default(1);
            $table->string('event_local_name', 64);
            $table->string('biz_step', 191)->nullable();
            $table->string('event_time', 64)->nullable();
            $table->unsignedInteger('seq')->default(0);
            $table->char('xml_sha256', 64);
            $table->longText('event_xml');
            $table->timestamps();

            $table->foreign('document_id')
                ->references('id')
                ->on('epcis_documents')
                ->cascadeOnDelete();

            $table->unique(['document_id', 'ingest_generation', 'xml_sha256'], 'epcis_pedigree_evt_doc_gen_hash_uq');
            $table->index(['document_id', 'ingest_generation'], 'epcis_pedigree_evt_doc_gen_idx');
            $table->index(['biz_step', 'document_id'], 'epcis_pedigree_evt_biz_doc_idx');
        });

        Schema::create('epcis_pedigree_vocab_fragments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('ingest_generation')->default(1);
            $table->string('vocabulary_type', 32);
            $table->string('element_id', 512)->nullable();
            $table->char('xml_sha256', 64);
            $table->mediumText('element_xml');
            $table->timestamps();

            $table->foreign('document_id')
                ->references('id')
                ->on('epcis_documents')
                ->cascadeOnDelete();

            $table->unique(['document_id', 'ingest_generation', 'xml_sha256'], 'epcis_pedigree_vocab_doc_gen_hash_uq');
            $table->index(['document_id', 'ingest_generation', 'vocabulary_type'], 'epcis_pedigree_vocab_doc_gen_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epcis_pedigree_vocab_fragments');
        Schema::dropIfExists('epcis_pedigree_event_fragments');
    }
};
