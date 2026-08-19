<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fda_wdd_3pl_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_matched')->default(0);
            $table->unsignedInteger('rows_skipped_unmatched')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->index(['completed_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fda_wdd_3pl_import_runs');
    }
};
