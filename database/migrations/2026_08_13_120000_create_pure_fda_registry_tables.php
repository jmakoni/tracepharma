<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fda_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('original_name');
            $table->string('canonical_name')->unique();
            $table->string('duns_number', 9)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('fda_establishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fda_organization_id')->constrained('fda_organizations')->restrictOnDelete();
            $table->string('fei_number', 20)->nullable()->unique();
            $table->string('firm_name');
            $table->string('duns_number', 9)->nullable();
            $table->string('street_address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state_province', 64)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->text('full_address')->nullable();
            $table->char('address_fingerprint', 64);
            $table->date('expiration_date')->nullable();
            $table->boolean('exclusion_flag')->default(false);
            $table->boolean('is_currently_registered')->default(true);
            $table->string('establishment_contact_name')->nullable();
            $table->string('establishment_contact_email')->nullable();
            $table->text('agent_details')->nullable();
            $table->string('registrant_contact_name')->nullable();
            $table->string('registrant_contact_email')->nullable();
            $table->timestamps();

            $table->index('fda_organization_id');
            $table->index(['fda_organization_id', 'address_fingerprint'], 'fda_est_org_fingerprint_idx');
        });

        Schema::create('fda_establishment_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fda_establishment_id')->constrained('fda_establishments')->cascadeOnDelete();
            $table->string('operation_code', 80);
            $table->timestamps();
            $table->unique(['fda_establishment_id', 'operation_code'], 'fda_est_ops_unique');
        });

        Schema::create('fda_wdd_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fda_organization_id')->constrained('fda_organizations')->restrictOnDelete();
            $table->string('facility_type', 8);
            $table->string('facility_name')->nullable();
            $table->string('alternate_name')->nullable();
            $table->string('street_address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state_province', 2)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->text('full_address')->nullable();
            $table->char('address_fingerprint', 64);
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamps();

            $table->unique(
                ['fda_organization_id', 'facility_type', 'address_fingerprint'],
                'fda_wdd_fac_org_type_fp_unique'
            );
        });

        Schema::create('fda_wdd_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fda_wdd_facility_id')->constrained('fda_wdd_facilities')->cascadeOnDelete();
            $table->string('license_number', 100);
            $table->char('jurisdiction', 2);
            $table->date('expiration_date')->nullable();
            $table->unsignedSmallInteger('reporting_year')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['fda_wdd_facility_id', 'jurisdiction', 'license_number'],
                'fda_wdd_lic_facility_jurisdiction_number_unique'
            );
        });

        Schema::create('fda_organization_match_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('original_name');
            $table->string('canonical_name')->nullable();
            $table->string('duns_number', 9)->nullable();
            $table->unsignedBigInteger('proposed_fda_organization_id')->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('status', 32)->default('pending');
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->foreign('proposed_fda_organization_id', 'fda_org_match_reviews_org_fk')
                ->references('id')
                ->on('fda_organizations')
                ->nullOnDelete();

            $table->index(['status', 'source']);
        });

        Schema::create('fda_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('source_path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_sent_to_review')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['source', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fda_import_runs');
        Schema::dropIfExists('fda_organization_match_reviews');
        Schema::dropIfExists('fda_wdd_licenses');
        Schema::dropIfExists('fda_wdd_facilities');
        Schema::dropIfExists('fda_establishment_operations');
        Schema::dropIfExists('fda_establishments');
        Schema::dropIfExists('fda_organizations');
    }
};
