<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('receiving_sessions', 'wms_receive_confirmed_at')) {
                $table->dateTime('wms_receive_confirmed_at', 6)->nullable()->after('receiving_events_generated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('receiving_sessions', 'wms_receive_confirmed_at')) {
                $table->dropColumn('wms_receive_confirmed_at');
            }
        });
    }
};
