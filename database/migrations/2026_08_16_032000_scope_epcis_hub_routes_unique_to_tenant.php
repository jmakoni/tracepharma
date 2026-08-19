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
            $table->dropUnique(['provider', 'gln']);
            $table->unique(['tenant_id', 'provider', 'gln']);
        });
    }

    public function down(): void
    {
        Schema::table('epcis_hub_routes', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'provider', 'gln']);
            $table->unique(['provider', 'gln']);
        });
    }
};
