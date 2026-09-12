<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            $table->boolean('is_cmo')->default(false)->after('is_active');
            $table->boolean('auto_receive_inbound')->default(false)->after('is_cmo');
        });
    }

    public function down(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            $table->dropColumn(['is_cmo', 'auto_receive_inbound']);
        });
    }
};
