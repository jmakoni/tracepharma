<?php

declare(strict_types=1);

namespace App\Support\EpcisJobs;

use App\Actions\EpcisJobs\ArchiveEpcisJob;
use App\Actions\EpcisJobs\CancelEpcisJob;
use App\Actions\EpcisJobs\ForceFailEpcisJob;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use DomainException;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the inbound epcis_jobs ledger in sync with ProcessEpcisDocumentJob / document status.
 */
final class SyncInboundEpcisJobFromDocument
{
    public function __construct(
        private readonly EpcisJobLogger $logger,
        private readonly EpcisJobStats $stats,
    ) {}

    public function findActive(int $documentId): ?EpcisJob
    {
        return EpcisJob::query()
            ->where('epcis_document_id', $documentId)
            ->where('kind', EpcisJobKind::InboundProcess->value)
            ->whereIn('status', [
                EpcisJobStatus::Queued->value,
                EpcisJobStatus::Processing->value,
            ])
            ->whereNull('archived_at')
            ->latest('id')
            ->first();
    }

    /**
     * Latest non-archived inbound ledger for the document (any status).
     * Used to honor cancel before a queued ProcessEpcisDocumentJob starts.
     */
    public function findLatest(int $documentId): ?EpcisJob
    {
        return EpcisJob::query()
            ->where('epcis_document_id', $documentId)
            ->where('kind', EpcisJobKind::InboundProcess->value)
            ->whereNull('archived_at')
            ->latest('id')
            ->first();
    }

    public function shouldSkipCancelled(int $documentId): bool
    {
        $latest = $this->findLatest($documentId);

        return $latest !== null && $latest->status === EpcisJobStatus::Cancelled;
    }

    public function markProcessing(EpcisJob $job): void
    {
        if ($job->status?->isTerminal()) {
            return;
        }

        $job->forceFill([
            'status' => EpcisJobStatus::Processing,
            'started_at' => $job->started_at ?? now(),
            'attempt_count' => ((int) $job->attempt_count) + 1,
        ])->save();

        $this->logger->info($job, 'Inbound processing started.');
    }

    public function syncAfterProcess(EpcisJob $job, EpcisDocument $document): void
    {
        $job = $job->fresh() ?? $job;

        if ($job->status?->isTerminal()) {
            return;
        }

        $document = $document->fresh() ?? $document;
        $finished = now();
        $ms = $job->started_at !== null
            ? (int) max(0, $job->started_at->diffInMilliseconds($finished))
            : null;

        if (in_array($document->status, ['parsed', 'validated'], true)) {
            $job->forceFill([
                'status' => EpcisJobStatus::Complete,
                'finished_at' => $finished,
                'processing_time_ms' => $ms,
                'error_message' => null,
                'stats_json' => $this->stats->forDocument($document, $ms),
            ])->save();
            $this->logger->info($job, 'Inbound processing complete ('.$document->status.').');

            return;
        }

        $job->forceFill([
            'status' => EpcisJobStatus::Error,
            'finished_at' => $finished,
            'processing_time_ms' => $ms,
            'error_message' => $document->error_message,
            'stats_json' => $this->stats->forDocument($document, $ms),
        ])->save();
        $this->logger->error($job, $document->error_message ?: 'Inbound processing failed.');
    }

    public function markFailed(EpcisJob $job, string $message): void
    {
        $job = $job->fresh() ?? $job;

        if ($job->status?->isTerminal()) {
            return;
        }

        $job->forceFill([
            'status' => EpcisJobStatus::Error,
            'finished_at' => now(),
            'error_message' => $message,
        ])->save();

        $this->logger->error($job, $message);
    }

    /**
     * Cancel or force-fail an active inbound ledger, sync terminal rows, then archive
     * every non-archived inbound job for the document before a manual reprocess.
     */
    public function reconcileAndArchiveForReprocess(EpcisDocument $document): void
    {
        if (! Schema::hasTable('epcis_jobs')) {
            return;
        }

        $documentId = (int) $document->getKey();
        $cancel = app(CancelEpcisJob::class);
        $forceFail = app(ForceFailEpcisJob::class);
        $archive = app(ArchiveEpcisJob::class);

        $active = $this->findActive($documentId);

        if ($active !== null) {
            if ($active->status === EpcisJobStatus::Queued) {
                $cancel->handle($active);
            } elseif ($active->status === EpcisJobStatus::Processing) {
                if (EpcisJobSla::canForceFail($active)) {
                    $forceFail->handle($active);
                } elseif (EpcisJobSla::canCancel($active)) {
                    $cancel->handle($active);
                } else {
                    throw new DomainException(
                        "EPCIS document {$documentId} inbound job is still processing; wait for it to finish before reprocessing.",
                    );
                }
            }
        }

        EpcisJob::query()
            ->where('epcis_document_id', $documentId)
            ->where('kind', EpcisJobKind::InboundProcess->value)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->each(function (EpcisJob $job) use ($archive, $document): void {
                $job = $job->fresh() ?? $job;

                if (! $job->status?->isTerminal()) {
                    return;
                }

                if ($job->status !== EpcisJobStatus::Cancelled) {
                    $this->syncAfterProcess($job, $document->fresh() ?? $document);
                }

                $archive->handle($job->fresh() ?? $job);
            });
    }
}
