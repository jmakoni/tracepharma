<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Delete superseded ingest generations for a document, keeping only the active projection.
 *
 * Event children (event_epcs, parties, locations, biz transactions, quantities, ILMD)
 * cascade from epcis_events deletes. Retired aggregation_links (valid_to set) are
 * preserved: established_by_event_id is a non-cascading audit reference so reprocess
 * retirement survives prune.
 */
final class PruneSupersededIngestGenerations
{
    private const EVENT_DELETE_CHUNK = 500;

    /**
     * Delete all non-active generations after ingest_generation advances (both older and orphan rows).
     *
     * @return array{
     *     kept_generation: int,
     *     events_deleted: int,
     *     document_epcs_deleted: int,
     *     product_classes_deleted: int,
     *     locations_deleted: int,
     *     vocabulary_elements_deleted: int,
     * }
     */
    public function handle(EpcisDocument $document): array
    {
        $keptGeneration = (int) ($document->ingest_generation ?? 1);
        $empty = $this->emptyStats($keptGeneration);

        if ($keptGeneration < 1 || ! $this->schemaReady()) {
            return $empty;
        }

        $documentId = (int) $document->getKey();

        if (! $this->hasGenerationsToPrune($documentId, $keptGeneration, orphansOnly: false)) {
            return $empty;
        }

        return $this->deleteGenerations($documentId, $keptGeneration, orphansOnly: false);
    }

    /**
     * Delete failed partial reprocess leftovers (generations greater than the active pointer).
     *
     * Does not delete generations below the active pointer — the last good projection remains.
     *
     * @return array{
     *     kept_generation: int,
     *     events_deleted: int,
     *     document_epcs_deleted: int,
     *     product_classes_deleted: int,
     *     locations_deleted: int,
     *     vocabulary_elements_deleted: int,
     * }
     */
    public function pruneOrphanGenerations(EpcisDocument $document): array
    {
        // Keep the active pointer (default 1). A failed first ingest writes its
        // only projection at generation 1; treating "never processed" as kept=0
        // deleted those rows and left the document view empty. Failed reprocess
        // leftovers remain orphans because they are written at max(gen)+1.
        $keptGeneration = (int) ($document->ingest_generation ?? 1);
        $empty = $this->emptyStats($keptGeneration);

        if ($keptGeneration < 0 || ! $this->schemaReady()) {
            return $empty;
        }

        $documentId = (int) $document->getKey();

        if (! $this->hasGenerationsToPrune($documentId, $keptGeneration, orphansOnly: true)) {
            return $empty;
        }

        return $this->deleteGenerations($documentId, $keptGeneration, orphansOnly: true);
    }

    /**
     * @return array{
     *     kept_generation: int,
     *     events_deleted: int,
     *     document_epcs_deleted: int,
     *     product_classes_deleted: int,
     *     locations_deleted: int,
     *     vocabulary_elements_deleted: int,
     * }
     */
    private function emptyStats(int $keptGeneration): array
    {
        return [
            'kept_generation' => $keptGeneration,
            'events_deleted' => 0,
            'document_epcs_deleted' => 0,
            'product_classes_deleted' => 0,
            'locations_deleted' => 0,
            'vocabulary_elements_deleted' => 0,
        ];
    }

    private function schemaReady(): bool
    {
        return Schema::hasColumn('epcis_documents', 'ingest_generation')
            && Schema::hasColumn('epcis_events', 'ingest_generation');
    }

    private function hasGenerationsToPrune(int $documentId, int $keptGeneration, bool $orphansOnly): bool
    {
        $eventQuery = EpcisEvent::query()
            ->where('document_id', $documentId);
        $this->applyGenerationFilter($eventQuery, $keptGeneration, $orphansOnly);

        if ($eventQuery->exists()) {
            return true;
        }

        if (! Schema::hasTable('document_epcs')) {
            return false;
        }

        $documentEpcsQuery = DB::table('document_epcs')
            ->where('document_id', $documentId);
        $this->applyGenerationFilter($documentEpcsQuery, $keptGeneration, $orphansOnly);

        return $documentEpcsQuery->exists();
    }

    /**
     * @return array{
     *     kept_generation: int,
     *     events_deleted: int,
     *     document_epcs_deleted: int,
     *     product_classes_deleted: int,
     *     locations_deleted: int,
     *     vocabulary_elements_deleted: int,
     * }
     */
    private function deleteGenerations(int $documentId, int $keptGeneration, bool $orphansOnly): array
    {
        return DB::transaction(function () use ($documentId, $keptGeneration, $orphansOnly): array {
            $stats = $this->emptyStats($keptGeneration);

            if (Schema::hasTable('epcis_document_product_classes')) {
                $query = DB::table('epcis_document_product_classes')
                    ->where('document_id', $documentId);
                $this->applyGenerationFilter($query, $keptGeneration, $orphansOnly);
                $stats['product_classes_deleted'] = (int) $query->delete();
            }

            if (Schema::hasTable('epcis_document_locations')) {
                $query = DB::table('epcis_document_locations')
                    ->where('document_id', $documentId);
                $this->applyGenerationFilter($query, $keptGeneration, $orphansOnly);
                $stats['locations_deleted'] = (int) $query->delete();
            }

            if (Schema::hasTable('epcis_document_vocabulary_elements')) {
                $query = DB::table('epcis_document_vocabulary_elements')
                    ->where('document_id', $documentId);
                $this->applyGenerationFilter($query, $keptGeneration, $orphansOnly);
                $stats['vocabulary_elements_deleted'] = (int) $query->delete();
            }

            if (Schema::hasTable('document_epcs')) {
                $query = DB::table('document_epcs')
                    ->where('document_id', $documentId);
                $this->applyGenerationFilter($query, $keptGeneration, $orphansOnly);
                $stats['document_epcs_deleted'] = (int) $query->delete();
            }

            do {
                $eventQuery = EpcisEvent::query()
                    ->where('document_id', $documentId);
                $this->applyGenerationFilter($eventQuery, $keptGeneration, $orphansOnly);

                $events = $eventQuery
                    ->orderBy('id')
                    ->limit(self::EVENT_DELETE_CHUNK)
                    ->get();

                if ($events->isEmpty()) {
                    break;
                }

                try {
                    $events->unsearchable();
                } catch (Throwable) {
                    // Best-effort: DB purge must proceed even when Scout is unavailable.
                }

                $stats['events_deleted'] += (int) EpcisEvent::query()
                    ->whereIn('id', $events->modelKeys())
                    ->delete();
            } while (true);

            return $stats;
        });
    }

    /**
     * @param  Builder<EpcisEvent>|\Illuminate\Database\Query\Builder  $query
     */
    private function applyGenerationFilter($query, int $keptGeneration, bool $orphansOnly): void
    {
        if ($orphansOnly) {
            $query->where('ingest_generation', '>', $keptGeneration);
        } else {
            $query->where('ingest_generation', '!=', $keptGeneration);
        }
    }
}
