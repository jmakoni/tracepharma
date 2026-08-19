<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->string('ship_from_name', 255)->nullable()->after('ship_to_gln');
            $table->string('ship_from_site_name', 255)->nullable()->after('ship_from_name');
            $table->string('ship_to_name', 255)->nullable()->after('ship_from_site_name');
            $table->string('ship_to_site_name', 255)->nullable()->after('ship_to_name');
        });
    }

    public function down(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->dropColumn([
                'ship_from_name',
                'ship_from_site_name',
                'ship_to_name',
                'ship_to_site_name',
            ]);
        });
    }
};
