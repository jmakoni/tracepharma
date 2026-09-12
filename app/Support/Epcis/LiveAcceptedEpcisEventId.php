<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use App\Models\Epcis\EpcisEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped: a GS1 event_id already live on a different, non-voided document
 * (hot table or archive after retention MOVE).
 */
final class LiveAcceptedEpcisEventId
{
    public function existsOnOtherDocument(string $eventId, int $exceptDocumentId): bool
    {
        $eventId = trim($eventId);
        if ($eventId === '' || $exceptDocumentId <= 0) {
            return false;
        }

        if ($this->existsHotOnOtherDocument($eventId, $exceptDocumentId)) {
            return true;
        }

        return $this->existsArchivedOnOtherDocument($eventId, $exceptDocumentId);
    }

    private function existsHotOnOtherDocument(string $eventId, int $exceptDocumentId): bool
    {
        $query = EpcisEvent::query()
            ->where('event_id', $eventId)
            ->where('document_id', '!=', $exceptDocumentId)
            ->whereHas('document', function ($document): void {
                $document->where(function ($status): void {
                    $status->whereNull('status')
                        ->orWhereNotIn('status', ['error', 'voided']);
                });
            });

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            $query->whereNull('superseded_at');
        }

        return $query->exists();
    }

    private function existsArchivedOnOtherDocument(string $eventId, int $exceptDocumentId): bool
    {
        if (! Schema::hasTable('epcis_events_archive')) {
            return false;
        }

        $query = DB::table('epcis_events_archive')
            ->where('event_id', $eventId)
            ->where(function ($documentId) use ($exceptDocumentId): void {
                $documentId->whereNull('document_id')
                    ->orWhere('document_id', '!=', $exceptDocumentId);
            });

        if (Schema::hasColumn('epcis_events_archive', 'superseded_at')) {
            $query->whereNull('superseded_at');
        }

        if (! Schema::hasTable('epcis_documents')) {
            return $query->exists();
        }

        return $query
            ->where(function ($row): void {
                $row->whereNull('document_id')
                    ->orWhereIn('document_id', function ($documents): void {
                        $documents->select('id')
                            ->from('epcis_documents')
                            ->where(function ($status): void {
                                $status->whereNull('status')
                                    ->orWhereNotIn('status', ['error', 'voided']);
                            });
                    });
            })
            ->exists();
    }
}
