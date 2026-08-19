<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_trading_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('gln', 13)->nullable()->unique();
            $table->string('partner_type');
            $table->string('atp_license_number')->nullable();
            $table->string('atp_license_state', 2)->nullable();
            $table->date('atp_expires_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_trading_partners');
    }
};
