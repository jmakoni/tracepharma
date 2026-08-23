<?php

namespace App\Jobs;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\EpcisJobs\SyncInboundEpcisJobFromDocument;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

class ProcessEpcisDocumentJob implements ShouldBeUnique, ShouldQueue
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
        return (string) $this->tenant->getKey().':'.$this->documentId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(EpcisIngestionService $service): void
    {
        $this->tenant->run(function () use ($service): void {
            $document = EpcisDocument::query()->findOrFail($this->documentId);
            $ledgerSync = app(SyncInboundEpcisJobFromDocument::class);

            // Cancel sets status=cancelled; findActive() excludes it — check latest first.
            if ($ledgerSync->shouldSkipCancelled((int) $document->getKey())) {
                return;
            }

            $ledger = $ledgerSync->findActive((int) $document->getKey());

            if ($ledger !== null) {
                $ledgerSync->markProcessing($ledger);
            }

            try {
                $service->process($document);
            } catch (Throwable $e) {
                if ($ledger !== null) {
                    $ledgerSync->markFailed($ledger->fresh() ?? $ledger, Str::limit($e->getMessage(), 2000));
                }

                throw $e;
            }

            if ($ledger !== null) {
                $ledger = $ledger->fresh() ?? $ledger;

                if ($ledger->status?->isTerminal()) {
                    return;
                }

                $ledgerSync->syncAfterProcess($ledger, $document->fresh() ?? $document);
            }
        });
    }

    public function failed(?Throwable $e): void
    {
        $this->tenant->run(function () use ($e): void {
            $ledgerSync = app(SyncInboundEpcisJobFromDocument::class);
            if ($ledgerSync->shouldSkipCancelled($this->documentId)) {
                return;
            }

            $document = EpcisDocument::query()->find($this->documentId);
            $shouldMarkDocumentError = $document !== null && (
                $document->status === 'parsing'
                || ($document->direction === 'inbound' && $document->status === 'received')
            );
            if ($document === null || ! $shouldMarkDocumentError) {
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

            // Case promotion is outside ingest work: optional queued job when
            // config('tracepharma.exceptions.auto_promote_types') includes the type.
            if (in_array('INGESTION_PARSE_ERROR', config('tracepharma.exceptions.auto_promote_types', []), true)
                || in_array('ingest_failure', config('tracepharma.exceptions.auto_promote_types', []), true)) {
                PromoteEpcisExceptionToCaseJob::dispatch(
                    (string) $this->tenant->getKey(),
                    (int) $signal->getKey(),
                );
            }
        });
    }
}
