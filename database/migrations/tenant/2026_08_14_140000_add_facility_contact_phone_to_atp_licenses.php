<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atp_licenses', function (Blueprint $table): void {
            if (! Schema::hasColumn('atp_licenses', 'facility_contact_phone')) {
                $table->string('facility_contact_phone', 50)->nullable()->after('facility_contact_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('atp_licenses', function (Blueprint $table): void {
            if (Schema::hasColumn('atp_licenses', 'facility_contact_phone')) {
                $table->dropColumn('facility_contact_phone');
            }
        });
    }
};
