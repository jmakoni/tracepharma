<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epcis_hub_routes', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('provider', 32);
            $table->char('gln', 13);
            $table->string('sgln_urn')->nullable();
            $table->unsignedBigInteger('default_inbound_connection_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['provider', 'gln']);
            $table->index(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epcis_hub_routes');
    }
};
