<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atp_licenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('trading_partner_id')->nullable()->after('site_id')->index();
            $table->string('document_path')->nullable()->after('facility_contact_phone');
            $table->string('document_original_name')->nullable()->after('document_path');
            $table->string('verification_status', 32)->default('verified')->after('document_original_name');
        });

        // Partner-level licenses describe a company, not a facility.
        Schema::table('atp_licenses', function (Blueprint $table): void {
            $table->string('facility_type')->nullable()->change();
            $table->unsignedSmallInteger('reporting_year')->nullable()->change();
        });

        Schema::create('atp_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('endpoint');
            $table->string('issuer')->nullable();
            $table->string('subject_gln', 13)->nullable();
            $table->timestamp('credential_expires_at')->nullable();
            $table->string('header_sha256', 64);
            $table->text('raw_credential')->nullable();
            $table->timestamps();

            $table->index(['subject_gln', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atp_credentials');

        Schema::table('atp_licenses', function (Blueprint $table): void {
            $table->dropIndex(['trading_partner_id']);
            $table->dropColumn([
                'trading_partner_id',
                'document_path',
                'document_original_name',
                'verification_status',
            ]);
        });
    }
};
