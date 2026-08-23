<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('receiving_sessions', 'invoice_disk')) {
                $table->string('invoice_disk')->nullable();
            }

            if (! Schema::hasColumn('receiving_sessions', 'invoice_path')) {
                $table->string('invoice_path')->nullable();
            }

            if (! Schema::hasColumn('receiving_sessions', 'invoice_original_filename')) {
                $table->string('invoice_original_filename')->nullable();
            }

            if (! Schema::hasColumn('receiving_sessions', 'invoice_sha256')) {
                $table->string('invoice_sha256', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('receiving_sessions', function (Blueprint $table) {
            foreach (['invoice_sha256', 'invoice_original_filename', 'invoice_path', 'invoice_disk'] as $column) {
                if (Schema::hasColumn('receiving_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
