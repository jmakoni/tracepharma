<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->char('sender_gln', 13)->nullable()->after('trading_partner_id');
            $table->char('receiver_gln', 13)->nullable()->after('sender_gln');
        });
    }

    public function down(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->dropColumn(['sender_gln', 'receiver_gln']);
        });
    }
};
