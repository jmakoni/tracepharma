<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retired aggregation_links (valid_to set) must survive superseded-generation event
 * prune. The establishing-event FK used cascadeOnDelete, which removed audit rows
 * after reprocess retirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aggregation_links') || ! Schema::hasColumn('aggregation_links', 'established_by_event_id')) {
            return;
        }

        $foreignName = $this->foreignKeyName('aggregation_links', 'established_by_event_id');

        if ($foreignName === null) {
            return;
        }

        Schema::table('aggregation_links', function (Blueprint $table) use ($foreignName): void {
            $table->dropForeign($foreignName);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('aggregation_links') || ! Schema::hasColumn('aggregation_links', 'established_by_event_id')) {
            return;
        }

        if ($this->foreignKeyName('aggregation_links', 'established_by_event_id') !== null) {
            return;
        }

        Schema::table('aggregation_links', function (Blueprint $table): void {
            $table->foreign('established_by_event_id')
                ->references('id')
                ->on('epcis_events')
                ->cascadeOnDelete();
        });
    }

    private function foreignKeyName(string $table, string $column): ?string
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = collect(DB::select(
            'SELECT CONSTRAINT_NAME AS name
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$database, $table, $column],
        ))->first();

        return $row?->name;
    }
};
