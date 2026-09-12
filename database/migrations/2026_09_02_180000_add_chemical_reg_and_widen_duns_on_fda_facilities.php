<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_establishments', function (Blueprint $table): void {
            $table->string('duns_number', 14)->nullable()->change();
            $table->string('chemical_reg_number', 30)->nullable()->after('hin_number');
        });

        Schema::table('fda_wdd_facilities', function (Blueprint $table): void {
            $table->string('duns_number', 14)->nullable()->change();
            $table->string('chemical_reg_number', 30)->nullable()->after('hin_number');
        });

        Schema::table('fda_organizations', function (Blueprint $table): void {
            $table->string('duns_number', 14)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fda_establishments', function (Blueprint $table): void {
            $table->dropColumn('chemical_reg_number');
            $table->string('duns_number', 9)->nullable()->change();
        });

        Schema::table('fda_wdd_facilities', function (Blueprint $table): void {
            $table->dropColumn('chemical_reg_number');
            $table->string('duns_number', 9)->nullable()->change();
        });

        Schema::table('fda_organizations', function (Blueprint $table): void {
            $table->string('duns_number', 9)->nullable()->change();
        });
    }
};
