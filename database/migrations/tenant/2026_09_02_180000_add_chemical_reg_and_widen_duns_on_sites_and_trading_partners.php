<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->widenAndAddChemicalReg('sites');
        $this->widenAndAddChemicalReg('trading_partners');
    }

    public function down(): void
    {
        foreach (['sites', 'trading_partners'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'chemical_reg_number')) {
                    $table->dropColumn('chemical_reg_number');
                }

                if (Schema::hasColumn($tableName, 'duns_number')) {
                    $table->string('duns_number', 9)->nullable()->change();
                }
            });
        }
    }

    private function widenAndAddChemicalReg(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (Schema::hasColumn($tableName, 'duns_number')) {
                $table->string('duns_number', 14)->nullable()->change();
            }

            // Only add Chemical Reg when the prior DEA/HIN identifier set is present.
            // Path-scoped migrates must not leave chemical_reg without duns/dea/hin.
            if (
                ! Schema::hasColumn($tableName, 'chemical_reg_number')
                && Schema::hasColumn($tableName, 'duns_number')
                && Schema::hasColumn($tableName, 'hin_number')
            ) {
                $table->string('chemical_reg_number', 30)->nullable()->after('hin_number');
            }
        });
    }
};
