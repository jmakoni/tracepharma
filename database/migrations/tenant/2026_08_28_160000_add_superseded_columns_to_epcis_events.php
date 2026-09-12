<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TP-402: append-only L4 event store — soft-supersede prior ingest generations
 * instead of hard-deleting epcis_events on reprocess/prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('epcis_events')) {
            return;
        }

        Schema::table('epcis_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('epcis_events', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable()->after('ingest_generation');
            }

            if (! Schema::hasColumn('epcis_events', 'superseded_by_generation')) {
                $table->unsignedInteger('superseded_by_generation')->nullable()->after('superseded_at');
            }
        });

        Schema::table('epcis_events', function (Blueprint $table): void {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = collect($sm->getIndexes('epcis_events'))
                ->pluck('name')
                ->all();

            if (! in_array('epcis_events_doc_gen_superseded_idx', $indexes, true)) {
                $table->index(
                    ['document_id', 'ingest_generation', 'superseded_at'],
                    'epcis_events_doc_gen_superseded_idx',
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('epcis_events')) {
            return;
        }

        Schema::table('epcis_events', function (Blueprint $table): void {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = collect($sm->getIndexes('epcis_events'))
                ->pluck('name')
                ->all();

            if (in_array('epcis_events_doc_gen_superseded_idx', $indexes, true)) {
                $table->dropIndex('epcis_events_doc_gen_superseded_idx');
            }

            $drops = [];
            if (Schema::hasColumn('epcis_events', 'superseded_by_generation')) {
                $drops[] = 'superseded_by_generation';
            }
            if (Schema::hasColumn('epcis_events', 'superseded_at')) {
                $drops[] = 'superseded_at';
            }
            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
