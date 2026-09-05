<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TP-420: durable uniqueness for live (non-superseded, non-null) GS1 event_id.
 */
return new class extends Migration
{
    private const INDEX = 'epcis_events_live_event_id_unique';

    public function up(): void
    {
        if (! Schema::hasTable('epcis_events') || ! Schema::hasColumn('epcis_events', 'event_id')) {
            return;
        }

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            DB::statement("
                UPDATE epcis_events AS e
                INNER JOIN (
                    SELECT event_id, MIN(id) AS keep_id
                    FROM epcis_events
                    WHERE superseded_at IS NULL
                      AND event_id IS NOT NULL
                      AND event_id <> ''
                    GROUP BY event_id
                    HAVING COUNT(*) > 1
                ) AS d ON e.event_id = d.event_id AND e.id <> d.keep_id
                SET e.superseded_at = NOW()
                WHERE e.superseded_at IS NULL
            ");
        }

        if (! Schema::hasColumn('epcis_events', 'live_event_id')) {
            Schema::table('epcis_events', function (Blueprint $table): void {
                $table->string('live_event_id', 128)
                    ->nullable()
                    ->virtualAs("IF(superseded_at IS NULL AND event_id IS NOT NULL AND event_id <> '', event_id, NULL)");
            });
        }

        if (! $this->indexExists(self::INDEX)) {
            Schema::table('epcis_events', function (Blueprint $table): void {
                $table->unique('live_event_id', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('epcis_events')) {
            return;
        }

        if ($this->indexExists(self::INDEX)) {
            Schema::table('epcis_events', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn('epcis_events', 'live_event_id')) {
            Schema::table('epcis_events', function (Blueprint $table): void {
                $table->dropColumn('live_event_id');
            });
        }
    }

    private function indexExists(string $indexName): bool
    {
        return collect(DB::select('SHOW INDEX FROM epcis_events WHERE Key_name = ?', [$indexName]))
            ->isNotEmpty();
    }
};
