<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DECRS org identity is REGISTRANT_DUNS — identical canonical names with different
 * DUNS must coexist as separate fda_organizations rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_organizations', function (Blueprint $table): void {
            $table->dropUnique(['canonical_name']);
            $table->index('canonical_name');
        });
    }

    public function down(): void
    {
        Schema::table('fda_organizations', function (Blueprint $table): void {
            $table->dropIndex(['canonical_name']);
            $table->unique('canonical_name');
        });
    }
};
