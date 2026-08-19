<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epcis_documents')) {
            return;
        }

        if (! Schema::hasColumn('epcis_documents', 'received_via')) {
            Schema::table('epcis_documents', function (Blueprint $table): void {
                $table->string('received_via', 32)->nullable()->after('inbound_connection_id');
                $table->index(['direction', 'received_via'], 'epcis_documents_direction_received_via_index');
            });
        }

        // Backfill from integration notes / connection presence.
        DB::table('epcis_documents')
            ->where('direction', 'inbound')
            ->whereNull('received_via')
            ->where('notes', 'Received via https_webhook_hub')
            ->update(['received_via' => 'https_webhook_hub']);

        DB::table('epcis_documents')
            ->where('direction', 'inbound')
            ->whereNull('received_via')
            ->where('notes', 'Received via https_webhook')
            ->update(['received_via' => 'https_webhook']);

        DB::table('epcis_documents')
            ->where('direction', 'inbound')
            ->whereNull('received_via')
            ->where('notes', 'Received via sftp_poll')
            ->update(['received_via' => 'sftp_poll']);

        DB::table('epcis_documents')
            ->where('direction', 'inbound')
            ->whereNull('received_via')
            ->whereNotNull('inbound_connection_id')
            ->update(['received_via' => 'https_webhook']);

        // Remaining inbound rows without a connection were Filament uploads or CLI.
        // Prefer catalog visibility for historical partner uploads (Xttrium, etc.).
        DB::table('epcis_documents')
            ->where('direction', 'inbound')
            ->whereNull('received_via')
            ->update(['received_via' => 'filament_upload']);

        // Known shipping validation fixtures that were ingested as inbound by mistake.
        DB::table('epcis_documents')
            ->where('direction', 'inbound')
            ->where('original_filename', 'like', '%full-history-validate%')
            ->update(['received_via' => 'cli']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('epcis_documents') || ! Schema::hasColumn('epcis_documents', 'received_via')) {
            return;
        }

        Schema::table('epcis_documents', function (Blueprint $table): void {
            $table->dropIndex('epcis_documents_direction_received_via_index');
            $table->dropColumn('received_via');
        });
    }
};
