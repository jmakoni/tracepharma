<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('suspension_reason', 500)->nullable()->after('status');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->unsignedBigInteger('suspended_by')->nullable()->after('suspended_at');
        });

        Schema::create('platform_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('action', 120)->index();
            $table->string('target_type', 120)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('tenant_id', 64)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id'], 'platform_audit_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_events');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['suspension_reason', 'suspended_at', 'suspended_by']);
        });
    }
};
