<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sscc_number_ranges', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('scope');
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->string('company_prefix');
            $table->string('extension_digit', 1);
            $table->text('gs1_api_key')->nullable();
            $table->unsignedInteger('index')->default(1);
            $table->unsignedTinyInteger('increment_by')->default(1);
            $table->unsignedBigInteger('range_size');
            $table->unsignedBigInteger('start_number');
            $table->unsignedBigInteger('current_number');
            $table->unsignedTinyInteger('threshold_percentage')->default(80);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('remaining');
            $table->timestamp('threshold_notified_at')->nullable();
            $table->timestamps();

            $table->index(['company_prefix', 'extension_digit', 'status'], 'sscc_number_ranges_prefix_ext_status');
            $table->index(['scope', 'site_id'], 'sscc_number_ranges_scope_site');
            $table->index(['scope', 'trading_partner_id'], 'sscc_number_ranges_scope_partner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sscc_number_ranges');
    }
};
