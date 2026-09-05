<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_users', function (Blueprint $table): void {
            $table->unsignedInteger('failed_login_count')->default(0)->after('is_active');
            $table->timestamp('locked_until')->nullable()->after('failed_login_count');
            $table->timestamp('disabled_at')->nullable()->after('locked_until');
            $table->string('disabled_reason')->nullable()->after('disabled_at');
            $table->unsignedInteger('session_version')->default(0)->after('disabled_reason');

            $table->index('locked_until', 'portal_users_locked_until_index');
        });
    }

    public function down(): void
    {
        Schema::table('portal_users', function (Blueprint $table): void {
            $table->dropIndex('portal_users_locked_until_index');
            $table->dropColumn([
                'failed_login_count',
                'locked_until',
                'disabled_at',
                'disabled_reason',
                'session_version',
            ]);
        });
    }
};
