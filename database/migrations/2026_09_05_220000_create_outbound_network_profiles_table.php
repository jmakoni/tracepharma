<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_network_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('network_slug', 64);
            $table->string('environment', 16);
            $table->string('label');
            $table->string('default_transport', 32);
            $table->json('allowed_transports');
            $table->text('endpoint_url')->nullable();
            $table->text('as2_url')->nullable();
            $table->string('as2_to')->nullable();
            $table->string('as2_subject')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_locked')->default(true);
            $table->timestamps();

            $table->unique(['network_slug', 'environment'], 'onp_slug_env_unique');
            $table->index('network_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_network_profiles');
    }
};
