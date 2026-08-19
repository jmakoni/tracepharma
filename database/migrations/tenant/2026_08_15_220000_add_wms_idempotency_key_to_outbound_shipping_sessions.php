<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table) {
            $table->string('wms_idempotency_key', 255)->nullable()->after('notes');
            $table->unique('wms_idempotency_key', 'oss_wms_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table) {
            $table->dropUnique('oss_wms_idempotency_key_unique');
            $table->dropColumn('wms_idempotency_key');
        });
    }
};
