<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('read_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('sgln')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('read_points');
    }
};
