<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->index('dea_number', 'sites_dea_number_index');
        });
        Schema::table('trading_partners', function (Blueprint $table): void {
            $table->index('dea_number', 'trading_partners_dea_number_index');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex('sites_dea_number_index');
        });
        Schema::table('trading_partners', function (Blueprint $table): void {
            $table->dropIndex('trading_partners_dea_number_index');
        });
    }
};
