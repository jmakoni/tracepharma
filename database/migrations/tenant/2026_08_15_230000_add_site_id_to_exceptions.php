<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exceptions', function (Blueprint $table): void {
            $table->foreignId('site_id')
                ->nullable()
                ->after('trading_partner_id')
                ->constrained('sites')
                ->nullOnDelete();

            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('exceptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
