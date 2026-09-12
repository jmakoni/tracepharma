<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('principals', function (Blueprint $table): void {
            $table->string('external_ref', 128)
                ->nullable()
                ->after('gln');

            $table->unique('external_ref');
        });
    }

    public function down(): void
    {
        Schema::table('principals', function (Blueprint $table): void {
            $table->dropUnique(['external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};
