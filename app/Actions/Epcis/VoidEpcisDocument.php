<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use DomainException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Soft-void an errored EPCIS document so it no longer appears as an active ingest failure.
 * Retention-safe: does not delete payload or historical events.
 */
final class VoidEpcisDocument
{
    public function handle(EpcisDocument $document, ?string $reason = null, bool $force = false): EpcisDocument
    {
        if (! JobRoleAccess::allows(Permissions::NavExceptions)) {
            throw new DomainException('Exceptions are not authorized for your job role.');
        }

        if ($document->status === 'voided') {
            return $document;
        }

        if ($document->status !== 'error') {
            throw new DomainException(
                "EPCIS document {$document->getKey()} can only be voided from status [error].",
            );
        }

        if (! $force && Schema::hasTable('receiving_sessions')) {
            $activeReceiving = ReceivingSession::query()
                ->where('epcis_document_id', $document->getKey())
                ->whereIn('status', ['open', 'in_progress'])
                ->exists();

            if ($activeReceiving) {
                throw new DomainException(
                    "EPCIS document {$document->getKey()} has an open or in-progress receiving session.",
                );
            }
        }

        $note = filled($reason)
            ? Str::limit(trim((string) $reason), 2000)
            : 'Voided by user.';

        $existingNotes = trim((string) ($document->notes ?? ''));
        $mergedNotes = $existingNotes === ''
            ? $note
            : Str::limit($existingNotes."\n".$note, 5000);

        $document->forceFill([
            'status' => 'voided',
            'error_message' => $note,
            'notes' => $mergedNotes,
        ])->save();

        if (function_exists('activity')) {
            activity()
                ->performedOn($document)
                ->withProperties(['reason' => $note])
                ->log('epcis_document_voided');
        }

        return $document->refresh();
    }
}
