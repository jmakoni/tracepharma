<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_onboardings', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('submitted');
            $table->string('legal_company_name');
            $table->string('company_display_name');
            $table->string('contact_name');
            $table->string('contact_email');
            $table->string('contact_phone')->nullable();
            $table->string('contact_role')->nullable();
            $table->string('organization_type');
            $table->string('tenant_profile')->nullable();
            $table->string('tenant_type')->nullable();
            $table->string('gln', 13)->nullable();
            $table->string('tenant_slug')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->text('message')->nullable();
            $table->foreignId('demo_request_id')->nullable()->constrained('demo_requests')->nullOnDelete();
            $table->string('tenant_id')->nullable();
            $table->string('terms_version');
            $table->string('privacy_version');
            $table->timestamp('terms_accepted_at');
            $table->timestamp('privacy_accepted_at');
            $table->string('acceptance_ip', 45)->nullable();
            $table->text('acceptance_user_agent')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_admin_user_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->string('submission_ip', 45)->nullable();
            $table->text('submission_user_agent')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('contact_email');
            $table->index('tenant_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_onboardings');
    }
};
