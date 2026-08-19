<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_atp_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_site_id')->nullable()->constrained('catalog_sites')->nullOnDelete();
            $table->string('facility_type');
            $table->string('license_number', 100);
            $table->string('license_state', 2);
            $table->date('license_expiration_date')->nullable();
            $table->unsignedInteger('reporting_year');
            $table->string('facility_contact_person')->nullable();
            $table->string('facility_contact_email')->nullable();
            $table->timestamps();

            $table->unique(['license_state', 'license_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_atp_licenses');
    }
};
