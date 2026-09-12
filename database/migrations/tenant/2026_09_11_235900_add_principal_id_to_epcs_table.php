<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epcs', function (Blueprint $table) {
            $table->foreignId('principal_id')
                ->nullable()
                ->after('product_id')
                ->constrained('principals')
                ->nullOnDelete();

            $table->index(['principal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('epcs', function (Blueprint $table) {
            $table->dropIndex(['principal_id', 'id']);
            $table->dropConstrainedForeignId('principal_id');
        });
    }
};
