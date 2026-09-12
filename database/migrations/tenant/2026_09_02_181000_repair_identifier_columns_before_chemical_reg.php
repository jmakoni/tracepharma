<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairIdentifiers('sites');
        $this->repairIdentifiers('trading_partners');
    }

    public function down(): void
    {
        // Irreversible repair: do not drop identifier columns that may have been
        // added to heal schema skew from a prior partial migrate.
    }

    private function repairIdentifiers(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $hasChemicalReg = Schema::hasColumn($tableName, 'chemical_reg_number');
        $hasDuns = Schema::hasColumn($tableName, 'duns_number');
        $hasDea = Schema::hasColumn($tableName, 'dea_number');
        $hasHin = Schema::hasColumn($tableName, 'hin_number');

        // Heal tenants that received chemical_reg without the 150000 identifier set.
        if ($hasChemicalReg && (! $hasDuns || ! $hasDea || ! $hasHin)) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $hasDuns, $hasDea, $hasHin): void {
                if (! $hasDuns) {
                    if (Schema::hasColumn($tableName, 'sgln')) {
                        $table->string('duns_number', 14)->nullable()->after('sgln');
                    } else {
                        $table->string('duns_number', 14)->nullable();
                    }
                }

                if (! $hasDea) {
                    $table->string('dea_number', 20)->nullable()->after('duns_number');
                }

                if (! $hasHin) {
                    $table->string('hin_number', 20)->nullable()->after('dea_number');
                }
            });
        }

        if (Schema::hasColumn($tableName, 'duns_number')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('duns_number', 14)->nullable()->change();
            });
        }

        // If identifiers exist but chemical_reg was skipped by hardened 180000, add it.
        if (
            ! Schema::hasColumn($tableName, 'chemical_reg_number')
            && Schema::hasColumn($tableName, 'duns_number')
            && Schema::hasColumn($tableName, 'hin_number')
        ) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('chemical_reg_number', 30)->nullable()->after('hin_number');
            });
        }
    }
};
