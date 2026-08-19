<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Epcis\RecordEpcisValidationFailure;
use App\Actions\Epcis\RunDomainEpcisHardGate;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use App\Services\Exceptions\PlatformSupportNotificationDispatcher;
use App\Support\EpcisJobs\SyncInboundEpcisJobFromDocument;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ingest once via {@see EpcisIngestionService} (which already runs ValidateEpcis12Document),
 * then emit an additional Domain hard-gate signal into epcis_exceptions on failure.
 *
 * True pre-persist hard gate for authoring remains {@see RunDomainEpcisHardGate::validateCandidate()}.
 * When status is already validated, process is skipped but Domain still runs (post-projection signal).
 * Do not dispatch this job on the same receive path as {@see ProcessEpcisDocumentJob}.
 */
class ValidateAndCommitEpcisDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public Tenant $tenant,
        public int $documentId,
    ) {}

    public function uniqueId(): string
    {
        return 'validate-commit:'.$this->tenant->getKey().':'.$this->documentId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->expireAfter(3600),
        ];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        RunDomainEpcisHardGate $hardGate,
        RecordEpcisValidationFailure $recordFailure,
        EpcisIngestionService $ingestion,
    ): void {
        $this->tenant->run(function () use ($hardGate, $recordFailure, $ingestion): void {
            $document = EpcisDocument::query()->findOrFail($this->documentId);
            $ledgerSync = app(SyncInboundEpcisJobFromDocument::class);

            if ($ledgerSync->shouldSkipCancelled((int) $document->getKey())) {
                return;
            }

            $ledger = $ledgerSync->findActive((int) $document->getKey());
            $alreadyValidated = $document->status === 'validated';

            if ($ledger !== null && ! $alreadyValidated) {
                $ledgerSync->markProcessing($ledger);
            }

            try {
                if (! $alreadyValidated) {
                    $ingestion->process($document);
                    $document = $document->fresh() ?? $document;
                }

                $domainResult = $hardGate->handle($document);
                if ($domainResult->isFailed()) {
                    $failure = $domainResult->failure;
                    assert($failure !== null);
                    $recordFailure->handle($document, $failure);
                    if ($ledger !== null) {
                        $ledgerSync->markFailed(
                            $ledger->fresh() ?? $ledger,
                            Str::limit($failure->message, 2000),
                        );
                    }

                    return;
                }

                if ($ledger !== null) {
                    $ledgerSync->syncAfterProcess($ledger->fresh() ?? $ledger, $document->fresh() ?? $document);
                }
            } catch (Throwable $e) {
                if ($ledger !== null) {
                    $ledgerSync->markFailed($ledger->fresh() ?? $ledger, Str::limit($e->getMessage(), 2000));
                }

                throw $e;
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->tenant->run(function () use ($e): void {
            $document = EpcisDocument::query()->find($this->documentId);
            if ($document === null || $document->status !== 'parsing') {
                $ledger = app(SyncInboundEpcisJobFromDocument::class)->findActive($this->documentId);
                if ($ledger !== null) {
                    app(SyncInboundEpcisJobFromDocument::class)->markFailed(
                        $ledger,
                        Str::limit($e?->getMessage() ?? 'Job failed', 2000),
                    );
                }

                return;
            }

            $message = Str::limit($e?->getMessage() ?? 'Job failed', 2000);

            $document->forceFill([
                'status' => 'error',
                'error_message' => $message,
            ])->save();

            $ledger = app(SyncInboundEpcisJobFromDocument::class)->findActive((int) $document->getKey());
            if ($ledger !== null) {
                app(SyncInboundEpcisJobFromDocument::class)->markFailed($ledger, $message);
            }

            $signal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'INGESTION_PARSE_ERROR',
                'severity' => 'error',
                'description' => $message,
                'status' => 'open',
            ]);

            if (in_array('INGESTION_PARSE_ERROR', config('tracepharma.exceptions.auto_promote_types', []), true)
                || in_array('ingest_failure', config('tracepharma.exceptions.auto_promote_types', []), true)) {
                PromoteEpcisExceptionToCaseJob::dispatch(
                    (string) $this->tenant->getKey(),
                    (int) $signal->getKey(),
                );
            }
        });

        app(PlatformSupportNotificationDispatcher::class)->dispatchForEpcisJobFailure(
            (string) $this->tenant->getKey(),
            $this->documentId,
            Str::limit($e?->getMessage() ?? 'Job failed', 2000),
        );
    }
}
