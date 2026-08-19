<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (! Schema::hasColumn('trading_partners', 'vrs_notify_email')) {
                $table->string('vrs_notify_email')->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (Schema::hasColumn('trading_partners', 'vrs_notify_email')) {
                $table->dropColumn('vrs_notify_email');
            }
        });
    }
};
