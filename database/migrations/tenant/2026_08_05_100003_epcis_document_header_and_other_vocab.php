<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('epcis_documents') && ! Schema::hasColumn('epcis_documents', 'header_json')) {
            Schema::table('epcis_documents', function (Blueprint $table) {
                $table->json('header_json')->nullable()->after('legal_notice');
            });
        }

        Schema::create('epcis_document_vocabulary_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('epcis_documents')->cascadeOnDelete();
            $table->unsignedInteger('ingest_generation');
            $table->string('vocabulary_type', 191);
            $table->string('element_id', 191);
            $table->json('attributes_json');
            $table->timestamps();

            $table->unique(
                ['document_id', 'ingest_generation', 'vocabulary_type', 'element_id'],
                'epcis_doc_vocab_elements_doc_gen_type_id_unique',
            );
            $table->index(
                ['document_id', 'ingest_generation'],
                'epcis_doc_vocab_elements_doc_gen_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epcis_document_vocabulary_elements');

        if (Schema::hasTable('epcis_documents') && Schema::hasColumn('epcis_documents', 'header_json')) {
            Schema::table('epcis_documents', function (Blueprint $table) {
                $table->dropColumn('header_json');
            });
        }
    }
};
