<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trading_partners', 'epcis_guideline')) {
            return;
        }

        DB::statement("ALTER TABLE trading_partners ALTER COLUMN epcis_guideline SET DEFAULT 'r12'");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('trading_partners', 'epcis_guideline')) {
            return;
        }

        DB::statement("ALTER TABLE trading_partners ALTER COLUMN epcis_guideline SET DEFAULT 'r13'");
    }
};
