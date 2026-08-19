<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exception_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('default_severity', 16)->default('medium');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exception_root_causes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exception_actions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exception_sla_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exception_type_id')->nullable()->constrained('exception_types')->nullOnDelete();
            $table->string('severity', 16);
            $table->unsignedSmallInteger('first_response_hours');
            $table->unsignedSmallInteger('resolve_hours');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['exception_type_id', 'severity'], 'exception_sla_type_severity_unique');
            $table->index(['severity', 'is_active']);
        });

        Schema::create('exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exception_type_id')->constrained('exception_types')->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('epcis_documents')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('epcis_events')->nullOnDelete();
            $table->foreignId('trading_partner_id')->nullable()->constrained('trading_partners')->nullOnDelete();
            $table->foreignId('compensating_document_id')->nullable()->constrained('epcis_documents')->nullOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('severity', 16);
            $table->string('status', 32)->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('root_cause_id')->nullable()->constrained('exception_root_causes')->nullOnDelete();
            $table->foreignId('resolution_action_id')->nullable()->constrained('exception_actions')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->unsignedInteger('serials_affected')->default(0);
            $table->timestamps();

            $table->index(['status', 'severity']);
            $table->index(['status', 'due_at']);
            $table->index('assigned_to');
            $table->index('trading_partner_id');
            $table->index('document_id');
            $table->index('exception_type_id');
        });

        Schema::create('exception_epcs', function (Blueprint $table) {
            $table->foreignId('exception_id')->constrained('exceptions')->cascadeOnDelete();
            $table->foreignId('epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->primary(['exception_id', 'epc_id']);
        });

        Schema::create('exception_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exception_id')->constrained('exceptions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('visibility', 16)->default('internal');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['exception_id', 'created_at']);
        });

        Schema::table('epcis_exceptions', function (Blueprint $table) {
            $table->foreignId('case_id')
                ->nullable()
                ->after('id')
                ->constrained('exceptions')
                ->nullOnDelete();
            $table->index('case_id');
        });
    }

    public function down(): void
    {
        Schema::table('epcis_exceptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('case_id');
        });

        Schema::dropIfExists('exception_activities');
        Schema::dropIfExists('exception_epcs');
        Schema::dropIfExists('exceptions');
        Schema::dropIfExists('exception_sla_rules');
        Schema::dropIfExists('exception_actions');
        Schema::dropIfExists('exception_root_causes');
        Schema::dropIfExists('exception_types');
    }
};
