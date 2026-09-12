<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_exports', function (Blueprint $table): void {
            $table->dropForeign(['requested_by_user_id']);
            $table->foreign('requested_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('data_exports', function (Blueprint $table): void {
            $table->dropForeign(['requested_by_user_id']);
            $table->foreign('requested_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
