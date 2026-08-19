<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fda_wdd_3pl_unmatched', function (Blueprint $table) {
            $table->id();
            $table->string('facility_name');
            $table->string('slug_attempt')->nullable()->index();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('catalog_trading_partner_id')->nullable()
                ->constrained('catalog_trading_partners')
                ->nullOnDelete();
            $table->unique('facility_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fda_wdd_3pl_unmatched');
    }
};
