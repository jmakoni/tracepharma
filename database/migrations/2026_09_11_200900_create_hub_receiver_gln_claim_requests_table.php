<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_receiver_gln_claim_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->char('gln', 13);
            $table->string('provider', 32);
            $table->text('reason');
            $table->string('requested_by');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(
                ['tenant_id', 'provider', 'gln', 'status'],
                'hrgcr_tenant_provider_gln_status_unique',
            );
            $table->index(['status', 'created_at'], 'hrgcr_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_receiver_gln_claim_requests');
    }
};
