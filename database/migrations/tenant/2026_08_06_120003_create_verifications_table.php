<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('verifications')) {
            return;
        }

        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            $table->string('gtin14', 14);
            $table->string('serial', 255);
            $table->string('lot', 255)->nullable();
            $table->string('status', 32);
            $table->string('scanned_barcode', 512)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            if (Schema::hasTable('exception_cases')) {
                $table->foreignId('exception_id')->nullable()->constrained('exception_cases')->nullOnDelete();
            } elseif (Schema::hasTable('exceptions')) {
                $table->foreignId('exception_id')->nullable()->constrained('exceptions')->nullOnDelete();
            } else {
                $table->unsignedBigInteger('exception_id')->nullable();
            }
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('message', 512)->nullable();
            $table->dateTime('verified_at', 6)->nullable();
            $table->timestamps();

            $table->index(['gtin14', 'serial']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};
