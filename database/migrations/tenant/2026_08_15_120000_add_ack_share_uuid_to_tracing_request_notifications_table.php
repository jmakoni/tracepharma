<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracing_request_notifications', function (Blueprint $table) {
            $table->uuid('ack_share_uuid')->nullable()->unique()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('tracing_request_notifications', function (Blueprint $table) {
            $table->dropUnique(['ack_share_uuid']);
            $table->dropColumn('ack_share_uuid');
        });
    }
};
