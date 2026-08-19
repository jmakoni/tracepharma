<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow the same parent/child/valid_from across ingest generations by scoping
 * uniqueness to the establishing event.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('aggregation_links')) {
            return;
        }

        Schema::table('aggregation_links', function (Blueprint $table): void {
            $table->dropUnique(['parent_epc_id', 'child_epc_id', 'valid_from']);
            $table->unique(
                ['parent_epc_id', 'child_epc_id', 'established_by_event_id'],
                'aggregation_links_parent_child_event_unique',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('aggregation_links')) {
            return;
        }

        Schema::table('aggregation_links', function (Blueprint $table): void {
            $table->dropUnique('aggregation_links_parent_child_event_unique');
            $table->unique(['parent_epc_id', 'child_epc_id', 'valid_from']);
        });
    }
};
