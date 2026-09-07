<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('outbound_connections', 'network_profile_id')) {
                $table->unsignedBigInteger('network_profile_id')->nullable()->after('system_key');
                $table->index('network_profile_id');
            }

            if (! Schema::hasColumn('outbound_connections', 'override_endpoint')) {
                $table->boolean('override_endpoint')->default(false)->after('network_profile_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('outbound_connections', function (Blueprint $table): void {
            if (Schema::hasColumn('outbound_connections', 'override_endpoint')) {
                $table->dropColumn('override_endpoint');
            }

            if (Schema::hasColumn('outbound_connections', 'network_profile_id')) {
                $table->dropIndex(['network_profile_id']);
                $table->dropColumn('network_profile_id');
            }
        });
    }
};
