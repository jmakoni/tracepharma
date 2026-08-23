<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (! Schema::hasColumn('trading_partners', 'customer_portal_uuid')) {
                $table->uuid('customer_portal_uuid')->nullable()->unique()->after('portal_share_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (Schema::hasColumn('trading_partners', 'customer_portal_uuid')) {
                $table->dropUnique(['customer_portal_uuid']);
                $table->dropColumn('customer_portal_uuid');
            }
        });
    }
};
