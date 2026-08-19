<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('gtin', 14)->unique();
            $table->string('name');
            $table->string('dosage_form')->nullable();
            $table->string('strength')->nullable();
            $table->string('manufacturer_name')->nullable();
            $table->string('ndc')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_products');
    }
};
