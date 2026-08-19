<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sscc_print_jobs', function (Blueprint $table): void {
            $table->string('client_print_token', 64)->nullable()->after('delivery_mode');
        });
    }

    public function down(): void
    {
        Schema::table('sscc_print_jobs', function (Blueprint $table): void {
            $table->dropColumn('client_print_token');
        });
    }
};
