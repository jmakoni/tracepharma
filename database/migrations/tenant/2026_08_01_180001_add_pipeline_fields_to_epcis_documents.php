<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('error_message');
            $table->unsignedInteger('reprocess_count')->default(0)->after('notes');
            $table->dateTime('last_processed_at', 6)->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->dropColumn([
                'notes',
                'reprocess_count',
                'last_processed_at',
            ]);
        });
    }
};
