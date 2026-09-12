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
            $table->string('duns_number', 9)->nullable()->after('sgln');
            $table->string('dea_number', 20)->nullable()->after('duns_number');
            $table->string('hin_number', 20)->nullable()->after('dea_number');
        });

        Schema::table('trading_partners', function (Blueprint $table): void {
            $table->string('duns_number', 9)->nullable()->after('sgln');
            $table->string('dea_number', 20)->nullable()->after('duns_number');
            $table->string('hin_number', 20)->nullable()->after('dea_number');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['duns_number', 'dea_number', 'hin_number']);
        });

        Schema::table('trading_partners', function (Blueprint $table): void {
            $table->dropColumn(['duns_number', 'dea_number', 'hin_number']);
        });
    }
};
