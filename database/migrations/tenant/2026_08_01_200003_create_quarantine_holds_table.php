<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quarantine_holds')) {
            return;
        }

        Schema::create('quarantine_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epc_id')->nullable()->constrained('epcs')->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('epcis_documents')->nullOnDelete();
            $table->string('reason');
            $table->string('status')->default('open');
            $table->string('severity')->default('warning');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'opened_at']);
            $table->index('document_id');
            $table->index('epc_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quarantine_holds');
    }
};
