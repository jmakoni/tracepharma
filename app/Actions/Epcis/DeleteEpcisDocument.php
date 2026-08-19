<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingSession;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Hard-delete an inbound EPCIS document and all document-scoped rows.
 * Shared epcs / epc_ilmd are retained (URI identity across documents).
 */
final class DeleteEpcisDocument
{
    private const ALLOWED_STATUSES = ['error', 'voided'];

    public function handle(EpcisDocument $document, ?string $reason = null, bool $force = false): void
    {
        if (! JobRoleAccess::allows(Permissions::NavExceptions)) {
            throw new DomainException('Exceptions are not authorized for your job role.');
        }

        $documentId = (int) $document->getKey();
        $payloadDisk = (string) ($document->payload_disk ?: 'local');
        $payloadPath = filled($document->payload_path) ? (string) $document->payload_path : null;
        $note = filled($reason)
            ? Str::limit(trim((string) $reason), 2000)
            : 'Deleted by user.';

        DB::transaction(function () use ($documentId, $force, $note): void {
            /** @var EpcisDocument $document */
            $document = EpcisDocument::query()
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            $status = (string) $document->status;
            if (! in_array($status, self::ALLOWED_STATUSES, true)) {
                throw new DomainException(
                    'EPCIS document '.$document->getKey()
                    .' can only be deleted from status [error|voided] (current: '.$status.').',
                );
            }

            if (! $force && Schema::hasTable('receiving_sessions')) {
                $activeReceiving = ReceivingSession::query()
                    ->where('epcis_document_id', $document->getKey())
                    ->whereIn('status', ['open', 'in_progress'])
                    ->exists();

                if ($activeReceiving) {
                    throw new DomainException(
                        'EPCIS document '.$document->getKey().' has an open or in-progress receiving session.',
                    );
                }
            }

            $properties = [
                'reason' => $note,
                'document_id' => $documentId,
                'document_uuid' => $document->document_uuid,
                'original_filename' => $document->original_filename,
                'file_sha256' => $document->file_sha256,
                'status' => $status,
                'event_count' => (int) $document->event_count,
                'epc_count' => (int) $document->epc_count,
                'ingest_generation' => (int) ($document->ingest_generation ?? 0),
            ];

            // Drop prior subject morph rows first, then record this delete (kept after purge).
            if (Schema::hasTable('activity_log')) {
                Activity::query()
                    ->where('subject_type', EpcisDocument::class)
                    ->where('subject_id', $documentId)
                    ->delete();
            }

            if (function_exists('activity')) {
                activity()
                    ->performedOn($document)
                    ->withProperties($properties)
                    ->log('epcis_document_deleted');
            }

            $eventIds = EpcisEvent::query()
                ->where('document_id', $documentId)
                ->pluck('id');

            if ($eventIds->isNotEmpty() && Schema::hasTable('aggregation_links')) {
                DB::table('aggregation_links')
                    ->whereIn('established_by_event_id', $eventIds)
                    ->delete();
            }

            EpcisEvent::query()
                ->where('document_id', $documentId)
                ->orderBy('id')
                ->chunkById(
                    (int) config('scout.chunk.unsearchable', 500),
                    function ($events): void {
                        $events->unsearchable();
                    },
                );

            // Quarantine holds, exception cases, and EPCIS exception signals are DSCSA
            // investigation records, not document artifacts — they must survive this
            // purge. Their document_id (and compensating_document_id) columns are
            // nullOnDelete foreign keys, so the database detaches them from the
            // deleted document below without this action hard-deleting them.
            $document->unsearchable();
            $document->delete();
        });

        if ($payloadPath !== null) {
            try {
                Storage::disk($payloadDisk)->delete($payloadPath);
            } catch (\Throwable) {
                // Best-effort: DB purge already committed.
            }
        }
    }
}
