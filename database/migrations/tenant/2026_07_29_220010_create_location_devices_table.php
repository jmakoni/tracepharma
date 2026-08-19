<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_location_device_id')->nullable()->index();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('gln', 13)->unique();
            $table->decimal('altitude', 8, 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('logo', 2048)->nullable();
            $table->timestamps();
            $table->index('name');
        });

        DB::statement("ALTER TABLE location_devices ADD COLUMN sgln VARCHAR(50) GENERATED ALWAYS AS (IF(CHAR_LENGTH(gln) < 12, NULL, CONCAT('urn:epc:id:sgln:', LEFT(gln, 12), '.0'))) STORED");
    }

    public function down(): void
    {
        Schema::dropIfExists('location_devices');
    }
};
