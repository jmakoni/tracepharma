<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user_impersonation_tokens', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('token');
        });

        DB::table('tenant_user_impersonation_tokens')
            ->whereNull('public_id')
            ->pluck('token')
            ->each(function (string $token): void {
                DB::table('tenant_user_impersonation_tokens')
                    ->where('token', $token)
                    ->update(['public_id' => (string) Str::uuid()]);
            });
    }

    public function down(): void
    {
        Schema::table('tenant_user_impersonation_tokens', function (Blueprint $table): void {
            $table->dropColumn('public_id');
        });
    }
};
