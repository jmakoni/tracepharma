<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Soft-supersede non-active ingest generations for a document (append-only L4 event store).
 *
 * Marks prior/orphan {@see EpcisEvent} rows with superseded_at instead of DELETE so RCA
 * can still load historical event PKs, EPCs, bizStep, and times. Projection indexes
 * (document_epcs, vocab/location/product_class) for non-active gens are still removed.
 * Retired aggregation_links (valid_to set) remain; established_by_event_id keeps a live FK.
 */
final class PruneSupersededIngestGenerations
{
    private const EVENT_CHUNK = 500;

    /**
     * Soft-supersede all non-active generations after ingest_generation advances
     * (both older and orphan rows).
     *
     * @return array{
     *     kept_generation: int,
     *     events_superseded: int,
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

        return $this->supersedeGenerations($documentId, $keptGeneration, orphansOnly: false);
    }

    /**
     * Soft-supersede failed partial reprocess leftovers (generations greater than the active pointer).
     *
     * Does not touch generations at or below the active pointer — the last good projection remains.
     *
     * @return array{
     *     kept_generation: int,
     *     events_superseded: int,
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
        // would have wiped those rows. Failed reprocess leftovers remain orphans
        // because they are written at max(gen)+1.
        $keptGeneration = (int) ($document->ingest_generation ?? 1);
        $empty = $this->emptyStats($keptGeneration);

        if ($keptGeneration < 0 || ! $this->schemaReady()) {
            return $empty;
        }

        $documentId = (int) $document->getKey();

        if (! $this->hasGenerationsToPrune($documentId, $keptGeneration, orphansOnly: true)) {
            return $empty;
        }

        return $this->supersedeGenerations($documentId, $keptGeneration, orphansOnly: true);
    }

    /**
     * Tentatively retire prior generations on this document so a new generation
     * can persist the same GS1 event_id without violating live uniqueness.
     */
    public function supersedePriorGenerationsForAttempt(EpcisDocument $document, int $newGeneration): void
    {
        if ($newGeneration <= 1 || ! $this->softSupersedeReady()) {
            return;
        }

        EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->where('ingest_generation', '<', $newGeneration)
            ->whereNull('superseded_at')
            ->update([
                'superseded_at' => now(),
                'superseded_by_generation' => $newGeneration,
                'updated_at' => now(),
            ]);
    }

    /**
     * Undo {@see supersedePriorGenerationsForAttempt} when the new generation fails.
     */
    public function restoreTentativeSupersede(EpcisDocument $document, int $newGeneration): void
    {
        if ($newGeneration <= 1 || ! $this->softSupersedeReady()) {
            return;
        }

        EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->where('ingest_generation', $newGeneration)
            ->whereNull('superseded_at')
            ->update([
                'superseded_at' => now(),
                'superseded_by_generation' => $newGeneration,
                'updated_at' => now(),
            ]);

        EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->where('ingest_generation', '<', $newGeneration)
            ->where('superseded_by_generation', $newGeneration)
            ->update([
                'superseded_at' => null,
                'superseded_by_generation' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{
     *     kept_generation: int,
     *     events_superseded: int,
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
            'events_superseded' => 0,
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

    private function softSupersedeReady(): bool
    {
        return Schema::hasColumn('epcis_events', 'superseded_at')
            && Schema::hasColumn('epcis_events', 'superseded_by_generation');
    }

    private function hasGenerationsToPrune(int $documentId, int $keptGeneration, bool $orphansOnly): bool
    {
        $eventQuery = EpcisEvent::query()
            ->where('document_id', $documentId);
        $this->applyGenerationFilter($eventQuery, $keptGeneration, $orphansOnly);
        $this->applyNotYetSupersededFilter($eventQuery);

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
     *     events_superseded: int,
     *     document_epcs_deleted: int,
     *     product_classes_deleted: int,
     *     locations_deleted: int,
     *     vocabulary_elements_deleted: int,
     * }
     */
    private function supersedeGenerations(int $documentId, int $keptGeneration, bool $orphansOnly): array
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

            if (! $this->softSupersedeReady()) {
                // Pre-migration safety: never hard-delete events; leave them for RCA.
                return $stats;
            }

            do {
                $eventQuery = EpcisEvent::query()
                    ->where('document_id', $documentId);
                $this->applyGenerationFilter($eventQuery, $keptGeneration, $orphansOnly);
                $this->applyNotYetSupersededFilter($eventQuery);

                $events = $eventQuery
                    ->orderBy('id')
                    ->limit(self::EVENT_CHUNK)
                    ->get();

                if ($events->isEmpty()) {
                    break;
                }

                try {
                    $events->unsearchable();
                } catch (Throwable) {
                    // Best-effort: soft-supersede must proceed even when Scout is unavailable.
                }

                $stats['events_superseded'] += (int) EpcisEvent::query()
                    ->whereIn('id', $events->modelKeys())
                    ->update([
                        'superseded_at' => now(),
                        'superseded_by_generation' => $keptGeneration,
                        'updated_at' => now(),
                    ]);
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

    /**
     * @param  Builder<EpcisEvent>|\Illuminate\Database\Query\Builder  $query
     */
    private function applyNotYetSupersededFilter($query): void
    {
        if ($this->softSupersedeReady()) {
            $query->whereNull('superseded_at');
        }
    }
}
