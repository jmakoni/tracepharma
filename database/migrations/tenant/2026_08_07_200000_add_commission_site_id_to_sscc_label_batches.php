<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sscc_label_batches', function (Blueprint $table): void {
            $table->foreignId('commission_site_id')
                ->nullable()
                ->after('label_printer_id')
                ->constrained('sites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sscc_label_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commission_site_id');
        });
    }
};
