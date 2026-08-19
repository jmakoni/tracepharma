<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fda_3911_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('draft');
            $table->string('classification')->default('illegitimate');
            $table->foreignId('verification_id')->nullable()->constrained('verifications')->nullOnDelete();
            $table->foreignId('exception_id')->nullable()->constrained('exceptions')->nullOnDelete();
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->string('incident_number')->nullable();
            $table->timestamp('determined_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('notifier_name')->nullable();
            $table->string('notifier_title')->nullable();
            $table->string('notifier_phone')->nullable();
            $table->string('notifier_email')->nullable();
            $table->string('facility_name')->nullable();
            $table->string('facility_gln', 13)->nullable();
            $table->text('facility_address')->nullable();
            $table->string('product_ndc')->nullable();
            $table->string('product_name')->nullable();
            $table->string('product_gtin')->nullable();
            $table->string('lot')->nullable();
            $table->string('serial')->nullable();
            $table->string('strength')->nullable();
            $table->string('dosage_form')->nullable();
            $table->text('circumstances')->nullable();
            $table->json('trading_partner_notifications')->nullable();
            $table->string('generated_pdf_path')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('due_at');
            $table->index('exception_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fda_3911_reports');
    }
};
