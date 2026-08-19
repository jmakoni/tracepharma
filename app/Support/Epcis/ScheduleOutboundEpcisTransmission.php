<?php

namespace App\Support\Epcis;

use App\Actions\EpcisJobs\EnqueueEpcisJob;
use App\Enums\EpcisJobStatus;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Support\Tenancy\TenantKillSwitches;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Schedule outbound transmission after the authoring transaction commits.
 * When epcis_jobs.enabled, enqueues a managed EPCIS job; otherwise sync transmit.
 */
final class ScheduleOutboundEpcisTransmission
{
    public function __construct(
        private readonly OutboundEpcisTransmitter $transmitter,
        private readonly EnqueueEpcisJob $enqueueEpcisJob,
    ) {}

    public function afterPersist(?EpcisDocument $document, bool $generated = true): void
    {
        if (! $generated || $document === null) {
            return;
        }

        $documentId = (int) $document->getKey();

        $dispatch = function () use ($documentId): void {
            $document = EpcisDocument::query()->find($documentId);

            if ($document === null) {
                return;
            }

            if (TenantKillSwitches::forTenant()->outboundEpcisKilled()) {
                $this->markTransmissionFailed(
                    $documentId,
                    TenantKillSwitches::blockedMessage(TenantKillSwitches::OUTBOUND_EPCIS),
                );

                return;
            }

            try {
                if (config('tracepharma.epcis_jobs.enabled')) {
                    $this->enqueueEpcisJob->handle($document);

                    return;
                }

                $this->transmitter->transmit($document);
            } catch (Throwable $e) {
                Log::error('Outbound EPCIS transmit hook failed unexpectedly.', [
                    'document_id' => $documentId,
                    'error' => $e->getMessage(),
                ]);

                $this->markTransmissionFailed($documentId, $e->getMessage());
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }

    private function markTransmissionFailed(int $documentId, string $message): void
    {
        $document = EpcisDocument::query()->find($documentId);

        if ($document === null) {
            return;
        }

        $document->forceFill([
            'transmission_status' => 'failed',
            'error_message' => $message,
        ])->save();

        EpcisJob::query()
            ->where('epcis_document_id', $documentId)
            ->whereNull('archived_at')
            ->whereIn('status', [
                EpcisJobStatus::Queued->value,
                EpcisJobStatus::Sending->value,
            ])
            ->update([
                'status' => EpcisJobStatus::Error->value,
                'error_message' => $message,
                'finished_at' => now(),
            ]);
    }
}
