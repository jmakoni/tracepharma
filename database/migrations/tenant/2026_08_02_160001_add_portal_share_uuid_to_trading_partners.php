<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (! Schema::hasColumn('trading_partners', 'portal_share_uuid')) {
                $table->uuid('portal_share_uuid')->nullable()->unique()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (Schema::hasColumn('trading_partners', 'portal_share_uuid')) {
                $table->dropUnique(['portal_share_uuid']);
                $table->dropColumn('portal_share_uuid');
            }
        });
    }
};
