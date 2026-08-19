<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracing_requests', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status')->default('open');
            $table->string('requestor_type')->default('internal');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('exception_id')->nullable()->constrained('exceptions')->nullOnDelete();
            $table->string('gtin')->nullable();
            $table->string('serial')->nullable();
            $table->string('lot')->nullable();
            $table->date('expiry')->nullable();
            $table->string('scope')->default('single_product');
            $table->boolean('is_recall')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('sla_breached')->default(false);
            $table->json('response_metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('requestor_type');
            $table->index('due_at');
            $table->index(['status', 'due_at']);
            $table->index('gtin');
            $table->index('lot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracing_requests');
    }
};
