<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracing_request_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracing_request_id')->constrained('tracing_requests')->cascadeOnDelete();
            $table->foreignId('trading_partner_id')->constrained('trading_partners')->cascadeOnDelete();
            $table->string('channel')->default('email');
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['tracing_request_id', 'trading_partner_id', 'channel'],
                'trn_request_partner_channel_unique',
            );
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracing_request_notifications');
    }
};
