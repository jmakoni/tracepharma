<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epcis_subscription_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('document_id');
            $table->string('trigger', 32)->nullable();
            $table->timestamp('delivered_at');
            $table->unique(['subscription_id', 'document_id'], 'epcis_sub_deliveries_sub_doc_unique');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epcis_subscription_deliveries');
    }
};
