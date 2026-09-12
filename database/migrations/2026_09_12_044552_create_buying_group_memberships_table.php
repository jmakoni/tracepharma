<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buying_group_memberships', function (Blueprint $table): void {
            $table->id();
            $table->string('buying_group_tenant_id');
            $table->string('member_tenant_id');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('bg_member_local_id')->nullable();
            $table->string('consent_version', 32)->nullable();
            $table->string('invited_by')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->string('accepted_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['buying_group_tenant_id', 'member_tenant_id'],
                'bgm_bg_member_unique',
            );
            $table->index(['member_tenant_id', 'status'], 'bgm_member_status_index');
            $table->index(['buying_group_tenant_id', 'status'], 'bgm_bg_status_index');

            $table->foreign('buying_group_tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->foreign('member_tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buying_group_memberships');
    }
};
