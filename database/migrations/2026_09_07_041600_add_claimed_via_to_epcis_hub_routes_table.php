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
            $table->string('claimed_via', 32)->default('connection_auto')->after('is_active');
        });

        Schema::table('epcis_hub_routes', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_inbound_connection_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('epcis_hub_routes', function (Blueprint $table): void {
            $table->dropColumn('claimed_via');
        });

        Schema::table('epcis_hub_routes', function (Blueprint $table): void {
            $table->unsignedBigInteger('default_inbound_connection_id')->nullable(false)->change();
        });
    }
};
