<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('verification_request_cases')) {
            Schema::create('verification_request_cases', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('secure_code_hash');
                $table->foreignId('verification_id')->constrained('verifications')->cascadeOnDelete();
                $table->foreignId('exception_id')->nullable();
                $table->foreign('exception_id', 'vr_cases_exception_fk')
                    ->references('id')
                    ->on('exceptions')
                    ->nullOnDelete();
                $table->foreignId('manufacturer_trading_partner_id')->nullable();
                $table->foreign('manufacturer_trading_partner_id', 'vr_cases_mfr_partner_fk')
                    ->references('id')
                    ->on('trading_partners')
                    ->nullOnDelete();
                $table->string('requestor_name');
                $table->string('requestor_gln', 13)->nullable();
                $table->string('requestor_license', 64)->nullable();
                $table->string('requestor_notify_email');
                $table->string('vendor_number', 64)->nullable();
                $table->string('gtin14', 14);
                $table->string('serial', 255);
                $table->string('lot', 255)->nullable();
                $table->string('expiry_yymmdd', 6)->nullable();
                $table->string('ndc11', 11)->nullable();
                $table->string('product_description')->nullable();
                $table->string('cin', 64)->nullable();
                $table->string('trigger_reason', 32);
                $table->string('status', 32)->default('pending');
                $table->text('notes')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('responded_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'expires_at']);
                $table->index('verification_id');
            });
        }

        if (! Schema::hasTable('verification_request_responses')) {
            Schema::create('verification_request_responses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('verification_request_case_id');
                $table->string('outcome', 16);
                $table->string('reason_code', 64);
                $table->text('comments')->nullable();
                $table->string('responder_email');
                $table->string('responder_ip', 45)->nullable();
                $table->string('attachment_path')->nullable();
                $table->timestamp('terms_accepted_at');
                $table->timestamps();

                $table->unique('verification_request_case_id', 'vr_resp_case_id_unique');
                $table->foreign('verification_request_case_id', 'vr_resp_case_fk')
                    ->references('id')
                    ->on('verification_request_cases')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_request_responses');
        Schema::dropIfExists('verification_request_cases');
    }
};
