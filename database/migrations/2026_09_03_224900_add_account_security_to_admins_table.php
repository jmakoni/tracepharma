<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('password');
            $table->unsignedInteger('failed_login_count')->default(0)->after('is_active');
            $table->timestamp('locked_until')->nullable()->after('failed_login_count');
            $table->timestamp('disabled_at')->nullable()->after('locked_until');
            $table->string('disabled_reason')->nullable()->after('disabled_at');
            $table->boolean('must_change_password')->default(false)->after('disabled_reason');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
            $table->unsignedInteger('session_version')->default(0)->after('password_changed_at');

            $table->index('locked_until', 'admins_locked_until_index');
            $table->index('is_active', 'admins_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->dropIndex('admins_locked_until_index');
            $table->dropIndex('admins_is_active_index');
            $table->dropColumn([
                'is_active',
                'failed_login_count',
                'locked_until',
                'disabled_at',
                'disabled_reason',
                'must_change_password',
                'password_changed_at',
                'session_version',
            ]);
        });
    }
};
