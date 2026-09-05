<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epcis_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('target_url', 2048);
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->string('directions', 16)->default('both'); // inbound|outbound|both
            $table->json('biz_step_filter')->nullable();
            $table->string('format', 32)->default('jsonld_20');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'directions']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epcis_subscriptions');
    }
};
