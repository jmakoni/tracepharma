<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outbound_shipping_sessions')) {
            return;
        }

        if (! Schema::hasColumn('outbound_shipping_sessions', 'is_corrective')) {
            Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
                $table->boolean('is_corrective')->default(false)->after('status');
                $table->text('corrective_reason')->nullable()->after('is_corrective');
            });
        }

        if (
            ! Schema::hasColumn('outbound_shipping_sessions', 'corrects_epcis_document_id')
            && Schema::hasTable('epcis_documents')
        ) {
            Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
                $table->foreignId('corrects_epcis_document_id')
                    ->nullable()
                    ->after('corrective_reason')
                    ->constrained('epcis_documents')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('outbound_shipping_sessions')) {
            return;
        }

        if (Schema::hasColumn('outbound_shipping_sessions', 'corrects_epcis_document_id')) {
            Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('corrects_epcis_document_id');
            });
        }

        if (Schema::hasColumn('outbound_shipping_sessions', 'is_corrective')) {
            Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
                $table->dropColumn(['is_corrective', 'corrective_reason']);
            });
        }
    }
};
