<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table) {
            $table->foreignId('principal_id')
                ->nullable()
                ->after('site_id')
                ->constrained('principals')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('principal_id');
        });
    }
};
