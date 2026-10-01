<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('table_views')) {
            return;
        }

        Schema::create('table_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('table_key', 191);
            $table->string('name', 100);
            $table->string('icon', 64)->nullable();
            $table->string('color', 32)->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_public')->default(false);
            $table->boolean('is_global')->default(false);
            $table->boolean('is_default')->default(false);
            $table->json('state');
            $table->timestamps();

            $table->index(['table_key', 'user_id']);
            $table->index(['table_key', 'is_global', 'is_favorite']);
            $table->index(['table_key', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_views');
    }
};
