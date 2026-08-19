<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->hardenEpcisDocuments();
        $this->hardenEpcisEvents();
        $this->restrictEpcForeignKeys();
        $this->indexAggregationLinks();
        $this->hardenEpcsUniquesAndIndexes();
        $this->addEpcIlmdGtin14();
        $this->createDocumentEpcs();
    }

    public function down(): void
    {
        Schema::dropIfExists('document_epcs');

        if (Schema::hasTable('epc_ilmd') && Schema::hasColumn('epc_ilmd', 'gtin14')) {
            $this->dropIndexIfExists('epc_ilmd', 'epc_ilmd_gtin14_lot_number_index');
            Schema::table('epc_ilmd', function (Blueprint $table) {
                $table->dropColumn('gtin14');
            });
        }

        if (Schema::hasTable('epcs')) {
            $this->dropIndexIfExists('epcs', 'epcs_type_prefix_ind_item_idx');
            $this->dropIndexIfExists('epcs', 'epcs_ai_01_21_unique');
            $this->dropIndexIfExists('epcs', 'epcs_ai_00_unique');

            Schema::table('epcs', function (Blueprint $table) {
                if (! $this->indexExists('epcs', 'epcs_ai_01_21_index')) {
                    $table->index('ai_01_21', 'epcs_ai_01_21_index');
                }
                if (! $this->indexExists('epcs', 'epcs_ai_00_index')) {
                    $table->index('ai_00', 'epcs_ai_00_index');
                }
            });
        }

        if (Schema::hasTable('aggregation_links')) {
            $this->dropIndexIfExists('aggregation_links', 'aggregation_links_parent_valid_to_child_index');
            $this->dropIndexIfExists('aggregation_links', 'aggregation_links_child_valid_to_parent_index');
            $this->dropIndexIfExists('aggregation_links', 'aggregation_links_established_by_parent_index');
        }

        $this->restoreCascadeEpcForeignKeys();

        if (Schema::hasTable('epcis_events')) {
            $this->dropIndexIfExists('epcis_events', 'epcis_events_document_id_ingest_generation_index');
            $this->dropIndexIfExists('epcis_events', 'epcis_events_document_id_event_time_index');

            if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
                Schema::table('epcis_events', function (Blueprint $table) {
                    $table->dropColumn('ingest_generation');
                });
            }
        }

        if (Schema::hasTable('epcis_documents')) {
            $this->dropIndexIfExists('epcis_documents', 'epcis_documents_file_sha256_index');
            $this->dropIndexIfExists('epcis_documents', 'epcis_documents_status_creation_date_index');
            $this->dropIndexIfExists('epcis_documents', 'epcis_documents_sender_gln_index');
            $this->dropIndexIfExists('epcis_documents', 'epcis_documents_receiver_gln_index');
            $this->dropIndexIfExists('epcis_documents', 'epcis_documents_ship_from_gln_index');
            $this->dropIndexIfExists('epcis_documents', 'epcis_documents_ship_to_gln_index');

            Schema::table('epcis_documents', function (Blueprint $table) {
                $drop = [];
                if (Schema::hasColumn('epcis_documents', 'ingest_generation')) {
                    $drop[] = 'ingest_generation';
                }
                if (Schema::hasColumn('epcis_documents', 'document_uuid_synthesized')) {
                    $drop[] = 'document_uuid_synthesized';
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });

            DB::statement('ALTER TABLE epcis_documents MODIFY document_uuid CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE epcis_documents MODIFY dscsa_affirm TINYINT(1) NOT NULL DEFAULT 1');
        }
    }

    private function hardenEpcisDocuments(): void
    {
        if (! Schema::hasTable('epcis_documents')) {
            return;
        }

        DB::statement('ALTER TABLE epcis_documents MODIFY document_uuid VARCHAR(128) NOT NULL');
        DB::statement('ALTER TABLE epcis_documents MODIFY dscsa_affirm TINYINT(1) NOT NULL DEFAULT 0');

        Schema::table('epcis_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('epcis_documents', 'ingest_generation')) {
                $table->unsignedInteger('ingest_generation')->default(1)->after('document_uuid');
            }
            if (! Schema::hasColumn('epcis_documents', 'document_uuid_synthesized')) {
                $table->boolean('document_uuid_synthesized')->default(false)->after('ingest_generation');
            }
        });

        Schema::table('epcis_documents', function (Blueprint $table) {
            if (! $this->indexExists('epcis_documents', 'epcis_documents_file_sha256_index')) {
                $table->index('file_sha256', 'epcis_documents_file_sha256_index');
            }
            if (! $this->indexExists('epcis_documents', 'epcis_documents_status_creation_date_index')) {
                $table->index(['status', 'creation_date'], 'epcis_documents_status_creation_date_index');
            }

            foreach (['sender_gln', 'receiver_gln', 'ship_from_gln', 'ship_to_gln'] as $column) {
                $indexName = "epcis_documents_{$column}_index";
                if (Schema::hasColumn('epcis_documents', $column) && ! $this->indexExists('epcis_documents', $indexName)) {
                    $table->index($column, $indexName);
                }
            }
        });
    }

    private function hardenEpcisEvents(): void
    {
        if (! Schema::hasTable('epcis_events')) {
            return;
        }

        Schema::table('epcis_events', function (Blueprint $table) {
            if (! Schema::hasColumn('epcis_events', 'ingest_generation')) {
                $table->unsignedInteger('ingest_generation')->default(1)->after('document_id');
            }
        });

        Schema::table('epcis_events', function (Blueprint $table) {
            if (! $this->indexExists('epcis_events', 'epcis_events_document_id_ingest_generation_index')) {
                $table->index(['document_id', 'ingest_generation'], 'epcis_events_document_id_ingest_generation_index');
            }
            if (! $this->indexExists('epcis_events', 'epcis_events_document_id_event_time_index')) {
                $table->index(['document_id', 'event_time'], 'epcis_events_document_id_event_time_index');
            }
        });
    }

    private function restrictEpcForeignKeys(): void
    {
        if (Schema::hasTable('event_epcs')) {
            $this->recreateForeignKey('event_epcs', 'epc_id', 'epcs', 'id', 'restrict');
        }

        if (Schema::hasTable('aggregation_links')) {
            $this->recreateForeignKey('aggregation_links', 'parent_epc_id', 'epcs', 'id', 'restrict');
            $this->recreateForeignKey('aggregation_links', 'child_epc_id', 'epcs', 'id', 'restrict');
        }

        if (Schema::hasTable('epc_ilmd')) {
            $this->recreateForeignKey('epc_ilmd', 'epc_id', 'epcs', 'id', 'restrict');
        }
    }

    private function restoreCascadeEpcForeignKeys(): void
    {
        if (Schema::hasTable('event_epcs')) {
            $this->recreateForeignKey('event_epcs', 'epc_id', 'epcs', 'id', 'cascade');
        }

        if (Schema::hasTable('aggregation_links')) {
            $this->recreateForeignKey('aggregation_links', 'parent_epc_id', 'epcs', 'id', 'cascade');
            $this->recreateForeignKey('aggregation_links', 'child_epc_id', 'epcs', 'id', 'cascade');
        }

        if (Schema::hasTable('epc_ilmd')) {
            $this->recreateForeignKey('epc_ilmd', 'epc_id', 'epcs', 'id', 'cascade');
        }
    }

    private function indexAggregationLinks(): void
    {
        if (! Schema::hasTable('aggregation_links')) {
            return;
        }

        Schema::table('aggregation_links', function (Blueprint $table) {
            if (! $this->indexExists('aggregation_links', 'aggregation_links_parent_valid_to_child_index')) {
                $table->index(
                    ['parent_epc_id', 'valid_to', 'child_epc_id'],
                    'aggregation_links_parent_valid_to_child_index',
                );
            }
            if (! $this->indexExists('aggregation_links', 'aggregation_links_child_valid_to_parent_index')) {
                $table->index(
                    ['child_epc_id', 'valid_to', 'parent_epc_id'],
                    'aggregation_links_child_valid_to_parent_index',
                );
            }
            if (! $this->indexExists('aggregation_links', 'aggregation_links_established_by_parent_index')) {
                $table->index(
                    ['established_by_event_id', 'parent_epc_id'],
                    'aggregation_links_established_by_parent_index',
                );
            }
        });
    }

    private function hardenEpcsUniquesAndIndexes(): void
    {
        if (! Schema::hasTable('epcs')) {
            return;
        }

        $this->nullDuplicateColumnValues('epcs', 'ai_01_21');
        $this->nullDuplicateColumnValues('epcs', 'ai_00');

        $this->dropIndexIfExists('epcs', 'epcs_ai_01_21_index');
        $this->dropIndexIfExists('epcs', 'epcs_ai_00_index');

        Schema::table('epcs', function (Blueprint $table) {
            if (! $this->indexExists('epcs', 'epcs_ai_01_21_unique')) {
                $table->unique('ai_01_21', 'epcs_ai_01_21_unique');
            }
            if (! $this->indexExists('epcs', 'epcs_ai_00_unique')) {
                $table->unique('ai_00', 'epcs_ai_00_unique');
            }
            if (! $this->indexExists('epcs', 'epcs_type_prefix_ind_item_idx')) {
                $table->index(
                    ['epc_type', 'company_prefix', 'indicator_digit', 'item_reference'],
                    'epcs_type_prefix_ind_item_idx',
                );
            }
        });
    }

    private function addEpcIlmdGtin14(): void
    {
        if (! Schema::hasTable('epc_ilmd')) {
            return;
        }

        Schema::table('epc_ilmd', function (Blueprint $table) {
            if (! Schema::hasColumn('epc_ilmd', 'gtin14')) {
                $table->char('gtin14', 14)->nullable()->after('epc_id');
            }
        });

        DB::statement('
            UPDATE epc_ilmd
            INNER JOIN epcs ON epcs.id = epc_ilmd.epc_id
            SET epc_ilmd.gtin14 = epcs.gtin14
            WHERE epc_ilmd.gtin14 IS NULL
              AND epcs.gtin14 IS NOT NULL
        ');

        Schema::table('epc_ilmd', function (Blueprint $table) {
            if (! $this->indexExists('epc_ilmd', 'epc_ilmd_gtin14_lot_number_index')) {
                $table->index(['gtin14', 'lot_number'], 'epc_ilmd_gtin14_lot_number_index');
            }
        });
    }

    private function createDocumentEpcs(): void
    {
        if (! Schema::hasTable('document_epcs')) {
            Schema::create('document_epcs', function (Blueprint $table) {
                $table->foreignId('document_id')->constrained('epcis_documents')->cascadeOnDelete();
                $table->foreignId('epc_id')->constrained('epcs')->restrictOnDelete();
                $table->unsignedInteger('ingest_generation');

                $table->primary(['document_id', 'epc_id', 'ingest_generation']);
                $table->index(['document_id', 'ingest_generation']);
            });
        }

        if (! Schema::hasTable('event_epcs') || ! Schema::hasTable('epcis_events')) {
            return;
        }

        DB::statement('
            INSERT IGNORE INTO document_epcs (document_id, epc_id, ingest_generation)
            SELECT DISTINCT
                epcis_events.document_id,
                event_epcs.epc_id,
                COALESCE(epcis_events.ingest_generation, 1)
            FROM event_epcs
            INNER JOIN epcis_events ON epcis_events.id = event_epcs.event_id
        ');
    }

    /**
     * Drop and recreate a single-column foreign key with the given on-delete action.
     *
     * @param  'restrict'|'cascade'  $onDelete
     */
    private function recreateForeignKey(
        string $table,
        string $column,
        string $referencesTable,
        string $referencesColumn,
        string $onDelete,
    ): void {
        $foreignName = $this->foreignKeyName($table, $column);

        if ($foreignName !== null) {
            Schema::table($table, function (Blueprint $blueprint) use ($foreignName): void {
                $blueprint->dropForeign($foreignName);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencesTable, $referencesColumn, $onDelete): void {
            $foreign = $blueprint->foreign($column)->references($referencesColumn)->on($referencesTable);

            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
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

    private function nullDuplicateColumnValues(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $duplicates = DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        foreach ($duplicates as $value) {
            $keepId = DB::table($table)->where($column, $value)->orderBy('id')->value('id');

            DB::table($table)
                ->where($column, $value)
                ->where('id', '!=', $keepId)
                ->update([$column => null]);
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
            $blueprint->dropIndex($indexName);
        });
    }
};
