<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project distinct event_epcs for a document into document_epcs for the active ingest generation.
 */
final class SyncDocumentEpcsFromEvents
{
    public function handle(EpcisDocument $document): int
    {
        if (! Schema::hasTable('document_epcs')) {
            return 0;
        }

        $generation = $this->ensureDocumentIngestGeneration($document);
        $epcIds = $this->collectDistinctEpcIds($document, $generation);

        if ($epcIds === []) {
            $document->forceFill(['epc_count' => 0])->save();

            return 0;
        }

        $this->insertDocumentEpcs($document, $generation, $epcIds);
        $count = count($epcIds);

        $document->forceFill(['epc_count' => $count])->save();

        return $count;
    }

    private function ensureDocumentIngestGeneration(EpcisDocument $document): int
    {
        $generation = (int) ($document->ingest_generation ?? 0);

        if ($generation < 1) {
            $generation = 1;

            if (Schema::hasColumn('epcis_documents', 'ingest_generation')) {
                $document->forceFill(['ingest_generation' => $generation])->save();
            }
        }

        return $generation;
    }

    /**
     * @return list<int>
     */
    private function collectDistinctEpcIds(EpcisDocument $document, int $generation): array
    {
        $query = DB::table('event_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->where('epcis_events.document_id', $document->getKey())
            ->distinct()
            ->select('event_epcs.epc_id');

        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $query->where('epcis_events.ingest_generation', $generation);
        }

        return $query
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function insertDocumentEpcs(EpcisDocument $document, int $generation, array $epcIds): void
    {
        $rows = [];
        foreach ($epcIds as $epcId) {
            $rows[] = [
                'document_id' => $document->getKey(),
                'epc_id' => $epcId,
                'ingest_generation' => $generation,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('document_epcs')->insertOrIgnore($chunk);
        }
    }
}
