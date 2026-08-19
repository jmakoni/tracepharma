<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epcis_documents')) {
            return;
        }

        if (Schema::hasColumn('epcis_documents', 'l3_forwarded_at')) {
            return;
        }

        Schema::table('epcis_documents', function (Blueprint $table): void {
            $table->timestamp('l3_forwarded_at')->nullable()->after('transmission_status');
        });
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('epcis_documents')
            || ! Schema::hasColumn('epcis_documents', 'l3_forwarded_at')
        ) {
            return;
        }

        Schema::table('epcis_documents', function (Blueprint $table): void {
            $table->dropColumn('l3_forwarded_at');
        });
    }
};
