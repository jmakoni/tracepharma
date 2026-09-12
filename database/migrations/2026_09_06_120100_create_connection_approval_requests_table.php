<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('direction', 16);
            $table->unsignedBigInteger('connection_id');
            $table->string('connection_name');
            $table->string('provider', 64)->nullable();
            $table->string('transport', 32)->nullable();
            $table->string('counterparty')->nullable();
            $table->string('endpoint_host')->nullable();
            $table->string('requested_by')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'direction', 'connection_id'], 'car_tenant_direction_connection_unique');
            $table->index(['status', 'created_at'], 'car_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_approval_requests');
    }
};
