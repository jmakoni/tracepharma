<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable()->after('cancelled_at');
            $table->foreignId('voided_by_user_id')->nullable()->after('voided_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('void_epcis_document_id')->nullable()->after('voided_by_user_id')
                ->constrained('epcis_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('void_epcis_document_id');
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropColumn('voided_at');
        });
    }
};
