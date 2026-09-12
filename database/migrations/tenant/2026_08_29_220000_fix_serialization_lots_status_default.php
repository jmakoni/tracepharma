<?php

use App\Jobs\L3\ConvertAndAcceptGuardianLotJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `serialization_lots.status` defaulted to `accepted` (2026_08_29_210000), but every
 * write path ({@see ConvertAndAcceptGuardianLotJob}) explicitly sets
 * `processing` on create and only reaches `accepted` after the EPCIS document
 * validates. A default of `accepted` is a false-positive trap for any future
 * direct-insert path that forgets to set status explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('serialization_lots')) {
            return;
        }

        Schema::table('serialization_lots', function (Blueprint $table): void {
            $table->string('status')->default('processing')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('serialization_lots')) {
            return;
        }

        Schema::table('serialization_lots', function (Blueprint $table): void {
            $table->string('status')->default('accepted')->change();
        });
    }
};
