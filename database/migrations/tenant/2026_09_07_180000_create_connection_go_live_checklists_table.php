<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_go_live_checklists', function (Blueprint $table): void {
            $table->id();
            $table->string('connection_type', 16);
            $table->unsignedBigInteger('connection_id');
            $table->json('steps')->nullable();
            $table->unsignedBigInteger('signed_off_by')->nullable();
            $table->timestamp('signed_off_at')->nullable();
            $table->string('break_glass_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['connection_type', 'connection_id'], 'go_live_checklist_connection_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_go_live_checklists');
    }
};
