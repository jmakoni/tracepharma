<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epcis_unmatched_glns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('epcis_documents')->cascadeOnDelete();
            $table->char('gln', 13);
            $table->string('gln_uri', 80)->nullable();
            $table->string('context', 32);
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'gln', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epcis_unmatched_glns');
    }
};
