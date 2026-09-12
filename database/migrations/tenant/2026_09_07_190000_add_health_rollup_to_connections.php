<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inbound_connections', 'outbound_connections'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestamp('last_success_at')->nullable()->after('last_error');
                $table->timestamp('last_failure_at')->nullable()->after('last_success_at');
                $table->unsignedInteger('consecutive_failures')->default(0)->after('last_failure_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['inbound_connections', 'outbound_connections'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['last_success_at', 'last_failure_at', 'consecutive_failures']);
            });
        }
    }
};
