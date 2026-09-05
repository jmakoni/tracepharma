<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('serialization_lots') && ! Schema::hasIndex('serialization_lots', 'serialization_lots_status_index')) {
            Schema::table('serialization_lots', function (Blueprint $table): void {
                $table->index('status', 'serialization_lots_status_index');
            });
        }

        if (Schema::hasTable('l3_lot_feeds') && ! Schema::hasIndex('l3_lot_feeds', 'l3_lot_feeds_status_index')) {
            Schema::table('l3_lot_feeds', function (Blueprint $table): void {
                $table->index('status', 'l3_lot_feeds_status_index');
            });
        }

        if (Schema::hasTable('epcis_documents')
            && Schema::hasColumn('epcis_documents', 'l3_forwarded_at')
            && ! Schema::hasIndex('epcis_documents', 'epcis_documents_l3_forwarded_at_index')) {
            Schema::table('epcis_documents', function (Blueprint $table): void {
                $table->index('l3_forwarded_at', 'epcis_documents_l3_forwarded_at_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serialization_lots') && Schema::hasIndex('serialization_lots', 'serialization_lots_status_index')) {
            Schema::table('serialization_lots', function (Blueprint $table): void {
                $table->dropIndex('serialization_lots_status_index');
            });
        }

        if (Schema::hasTable('l3_lot_feeds') && Schema::hasIndex('l3_lot_feeds', 'l3_lot_feeds_status_index')) {
            Schema::table('l3_lot_feeds', function (Blueprint $table): void {
                $table->dropIndex('l3_lot_feeds_status_index');
            });
        }

        if (Schema::hasTable('epcis_documents') && Schema::hasIndex('epcis_documents', 'epcis_documents_l3_forwarded_at_index')) {
            Schema::table('epcis_documents', function (Blueprint $table): void {
                $table->dropIndex('epcis_documents_l3_forwarded_at_index');
            });
        }
    }
};
