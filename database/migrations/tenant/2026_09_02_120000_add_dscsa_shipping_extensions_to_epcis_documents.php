<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table): void {
            $table->string('direct_purchase_qualifier', 32)->nullable()->after('legal_notice');
            $table->text('direct_purchase_statement')->nullable()->after('direct_purchase_qualifier');
            $table->json('direct_purchase_indirect_epc_uris')->nullable()->after('direct_purchase_statement');
            $table->string('received_prev_wholesaler_qualifier', 32)->nullable()->after('direct_purchase_indirect_epc_uris');
            $table->text('received_prev_wholesaler_statement')->nullable()->after('received_prev_wholesaler_qualifier');
            $table->json('received_prev_wholesaler_indirect_epc_uris')->nullable()->after('received_prev_wholesaler_statement');
        });
    }

    public function down(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'direct_purchase_qualifier',
                'direct_purchase_statement',
                'direct_purchase_indirect_epc_uris',
                'received_prev_wholesaler_qualifier',
                'received_prev_wholesaler_statement',
                'received_prev_wholesaler_indirect_epc_uris',
            ]);
        });
    }
};
