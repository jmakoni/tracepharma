<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buying_group_member_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('member_tenant_id', 36);
            $table->date('as_of');
            $table->unsignedInteger('atp_gap_count')->default(0);
            $table->unsignedInteger('exceptions_open')->default(0);
            $table->unsignedInteger('exceptions_aging_7d')->default(0);
            $table->boolean('connection_unhealthy')->default(false);
            $table->timestamp('last_epcis_success_at')->nullable();
            $table->decimal('health_score', 5, 2)->nullable();
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['member_tenant_id', 'as_of'], 'bg_member_metrics_tenant_as_of_uq');
            $table->index('as_of');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buying_group_member_metrics');
    }
};
