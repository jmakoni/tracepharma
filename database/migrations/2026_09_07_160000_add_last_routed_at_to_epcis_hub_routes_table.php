<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epcis_hub_routes', function (Blueprint $table): void {
            $table->timestamp('last_routed_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('epcis_hub_routes', function (Blueprint $table): void {
            $table->dropColumn('last_routed_at');
        });
    }
};
