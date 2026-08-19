<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user_impersonation_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('admin_id')->nullable()->after('tenant_id');
            $table->text('reason')->nullable()->after('admin_id');
            $table->string('admin_ip', 45)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user_impersonation_tokens', function (Blueprint $table): void {
            $table->dropColumn(['admin_id', 'reason', 'admin_ip']);
        });
    }
};
