<?php

namespace App\Support\Epcis;

use App\Models\Epcis\EpcisDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Pedigree TI rebuild reads retained commission/pack payloads — not archived event rows.
 * Event archival ({@see \App\Actions\Epcis\ArchiveAgedEpcisEvents}) must never delete those files.
 *
 * @phpstan-type MissingPayloadRow array{
 *     document_id: int,
 *     payload_disk: string,
 *     payload_path: string,
 *     reason: string
 * }
 */
final class AuditPedigreePayloadRetention
{
    /**
     * Documents that still have commissioning/packing events in hot or archive tables
     * but whose on-disk payload is missing or unreadable.
     *
     * @return list<MissingPayloadRow>
     */
    public function missingPedigreePayloads(): array
    {
        $documentIds = $this->pedigreeSourceDocumentIds();
        if ($documentIds === []) {
            return [];
        }

        $missing = [];

        foreach (array_chunk($documentIds, 200) as $chunk) {
            $documents = EpcisDocument::query()
                ->whereIn('id', $chunk)
                ->get(['id', 'payload_disk', 'payload_path']);

            foreach ($documents as $document) {
                $path = trim((string) $document->payload_path);
                $disk = filled($document->payload_disk)
                    ? (string) $document->payload_disk
                    : (string) config('tracepharma.epcis.payload_disk', 'local');

                if ($path === '') {
                    $missing[] = [
                        'document_id' => (int) $document->getKey(),
                        'payload_disk' => $disk,
                        'payload_path' => '',
                        'reason' => 'empty_payload_path',
                    ];

                    continue;
                }

                try {
                    if (! Storage::disk($disk)->exists($path)) {
                        $missing[] = [
                            'document_id' => (int) $document->getKey(),
                            'payload_disk' => $disk,
                            'payload_path' => $path,
                            'reason' => 'missing_on_disk',
                        ];
                    }
                } catch (\Throwable) {
                    $missing[] = [
                        'document_id' => (int) $document->getKey(),
                        'payload_disk' => $disk,
                        'payload_path' => $path,
                        'reason' => 'disk_unreadable',
                    ];
                }
            }
        }

        return $missing;
    }

    /**
     * Payload retention must cover at least as long as event retention.
     */
    public function payloadRetentionYears(): int
    {
        $eventYears = max(1, (int) config('tracepharma.epcis.retention_years', 6));
        $payloadYears = (int) config('tracepharma.epcis.payload_retention_years', $eventYears);

        return max($eventYears, $payloadYears);
    }

    /**
     * @return list<int>
     */
    private function pedigreeSourceDocumentIds(): array
    {
        $ids = [];

        $hot = DB::table('epcis_events')
            ->where(function ($q): void {
                $q->where('biz_step', 'like', '%:commissioning')
                    ->orWhere('biz_step', 'like', '%:packing');
            })
            ->distinct()
            ->pluck('document_id');

        foreach ($hot as $id) {
            $ids[(int) $id] = true;
        }

        if (Schema::hasTable('epcis_events_archive')) {
            $archived = DB::table('epcis_events_archive')
                ->where(function ($q): void {
                    $q->where('biz_step', 'like', '%:commissioning')
                        ->orWhere('biz_step', 'like', '%:packing');
                })
                ->distinct()
                ->pluck('document_id');

            foreach ($archived as $id) {
                $ids[(int) $id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }
}
