<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epcis_document_product_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('epcis_documents')->cascadeOnDelete();
            $table->unsignedInteger('ingest_generation');
            $table->string('idpat', 191);
            $table->char('gtin14', 14)->nullable();
            $table->string('ndc_raw', 64)->nullable();
            $table->char('ndc11', 11)->nullable();
            $table->string('name')->nullable();
            $table->string('dosage_form', 128)->nullable();
            $table->string('strength', 128)->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('net_content')->nullable();
            $table->json('attributes_json');
            $table->timestamps();

            $table->unique(['document_id', 'ingest_generation', 'idpat'], 'epcis_doc_product_classes_doc_gen_idpat_unique');
            $table->index(['document_id', 'ingest_generation'], 'epcis_doc_product_classes_doc_gen_index');
            $table->index(['document_id', 'ingest_generation', 'gtin14'], 'epcis_doc_product_classes_doc_gen_gtin_index');
            $table->index('ndc11', 'epcis_doc_product_classes_ndc11_index');
        });

        Schema::create('epcis_document_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('epcis_documents')->cascadeOnDelete();
            $table->unsignedInteger('ingest_generation');
            $table->string('gln_uri', 128);
            $table->char('gln', 13)->nullable();
            $table->string('name')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code')->nullable();
            $table->json('attributes_json');
            $table->timestamps();

            $table->unique(['document_id', 'ingest_generation', 'gln_uri'], 'epcis_doc_locations_doc_gen_gln_uri_unique');
            $table->index(['document_id', 'ingest_generation', 'gln'], 'epcis_doc_locations_doc_gen_gln_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epcis_document_locations');
        Schema::dropIfExists('epcis_document_product_classes');
    }
};
