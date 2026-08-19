<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epcis_events')) {
            return;
        }

        $this->dropIndexIfExists('epcis_events', 'epcis_events_event_id_unique');

        if (! $this->indexExists('epcis_events', 'epcis_events_doc_gen_event_id_unique')) {
            Schema::table('epcis_events', function (Blueprint $table) {
                // Nullable event_id: MySQL allows multiple NULLs in a UNIQUE index.
                $table->unique(
                    ['document_id', 'ingest_generation', 'event_id'],
                    'epcis_events_doc_gen_event_id_unique',
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('epcis_events')) {
            return;
        }

        $this->dropIndexIfExists('epcis_events', 'epcis_events_doc_gen_event_id_unique');

        if (
            Schema::hasColumn('epcis_events', 'event_id')
            && ! $this->indexExists('epcis_events', 'epcis_events_event_id_unique')
        ) {
            Schema::table('epcis_events', function (Blueprint $table) {
                $table->unique('event_id', 'epcis_events_event_id_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(DB::select('SHOW INDEX FROM '.$table.' WHERE Key_name = ?', [$indexName]))
            ->isNotEmpty();
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            $blueprint->dropUnique($indexName);
        });
    }
};
