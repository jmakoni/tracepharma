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
            if (! Schema::hasColumn('trading_partners', 'epcis_guideline')) {
                $table->string('epcis_guideline', 8)
                    ->default('r12')
                    ->after('partner_type');
            }
        });

        Schema::table('epcis_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('epcis_documents', 'dscsa_guideline_release')) {
                $table->string('dscsa_guideline_release', 8)
                    ->nullable()
                    ->after('schema_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trading_partners', function (Blueprint $table): void {
            if (Schema::hasColumn('trading_partners', 'epcis_guideline')) {
                $table->dropColumn('epcis_guideline');
            }
        });

        Schema::table('epcis_documents', function (Blueprint $table): void {
            if (Schema::hasColumn('epcis_documents', 'dscsa_guideline_release')) {
                $table->dropColumn('dscsa_guideline_release');
            }
        });
    }
};
