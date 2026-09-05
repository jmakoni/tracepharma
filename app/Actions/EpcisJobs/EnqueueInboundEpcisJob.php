<?php

declare(strict_types=1);

namespace App\Actions\EpcisJobs;

use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Jobs\ProcessEpcisDocumentJob;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\Epcis\EpcisCacheLock;
use App\Support\EpcisJobs\EpcisJobLogger;
use App\Support\EpcisJobs\EpcisJobSla;
use App\Support\EpcisJobs\ReleaseEpcisJobUniqueLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Create an inbound process ledger row, then run the existing ProcessEpcisDocumentJob
 * (no second pipeline).
 */
final class EnqueueInboundEpcisJob
{
    public function __construct(
        private readonly EpcisJobLogger $logger,
        private readonly ReleaseEpcisJobUniqueLock $releaseUniqueLock,
    ) {}

    public function handle(EpcisDocument $document, bool $sync = false, ?int $requestedBy = null): EpcisJob
    {
        $document = $document->fresh() ?? $document;

        if ($document->direction !== 'inbound') {
            throw new RuntimeException('EnqueueInboundEpcisJob requires an inbound document.');
        }

        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');
        $lockKey = 'epcis-enqueue:'.$tenantId.':'.$document->getKey();

        return EpcisCacheLock::lock($lockKey, 30)->block(10, function () use ($document, $sync, $requestedBy): EpcisJob {
            $created = false;

            $job = DB::transaction(function () use ($document, $requestedBy, &$created): EpcisJob {
                EpcisDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

                $active = EpcisJob::query()
                    ->where('epcis_document_id', $document->getKey())
                    ->where('kind', EpcisJobKind::InboundProcess->value)
                    ->whereIn('status', [
                        EpcisJobStatus::Queued->value,
                        EpcisJobStatus::Processing->value,
                    ])
                    ->whereNull('archived_at')
                    ->first();

                if ($active !== null) {
                    return $active;
                }

                $receipt = str_replace('-', '', (string) Str::uuid());

                $job = EpcisJob::query()->create([
                    'receipt' => $receipt,
                    'kind' => EpcisJobKind::InboundProcess,
                    'status' => EpcisJobStatus::Queued,
                    'epcis_document_id' => $document->getKey(),
                    // Ledger column is ship_from_site_id; for inbound jobs it stores the receive site.
                    'ship_from_site_id' => $document->ship_to_site_id,
                    'requested_by' => $requestedBy ?? auth()->id(),
                    'original_filename' => $document->original_filename,
                    'received_at' => now(),
                    'attempt_count' => 0,
                ]);

                $this->logger->info($job, 'Inbound EPCIS job queued for processing.');
                $created = true;

                return $job;
            });

            if (! $created) {
                $staleJob = $job->fresh() ?? $job;
                if (EpcisJobSla::isStaleQueued($staleJob)) {
                    $this->redispatchStaleInboundJob($staleJob, $document, $sync);
                }

                return $job->fresh() ?? $job;
            }

            $tenant = tenant();
            if (! $tenant instanceof Tenant) {
                $this->markEnqueueFailed($job, 'Tenant context required to process inbound EPCIS.');

                throw new RuntimeException('Tenant context required to process inbound EPCIS.');
            }

            $runSync = $sync || Queue::getDefaultDriver() === 'sync';
            $processJob = new ProcessEpcisDocumentJob($tenant, (int) $document->getKey());

            if ($runSync) {
                // Calling handle() directly skips WithoutOverlapping middleware; mirror
                // ReceiveEpcisUpload so sync ingest cannot race a concurrent process.
                EpcisCacheLock::lock($this->epcisProcessLockKey($document), 600)->block(30, function () use ($processJob): void {
                    $processJob->handle(app(EpcisIngestionService::class));
                });
            } else {
                try {
                    ProcessEpcisDocumentJob::dispatch($tenant, (int) $document->getKey())
                        ->onQueue((string) config('tracepharma.epcis_jobs.queue', 'epcis'));
                } catch (Throwable $e) {
                    $this->markEnqueueFailed($job, $e->getMessage());

                    throw $e;
                }
            }

            return $job->fresh() ?? $job;
        });
    }

    private function redispatchStaleInboundJob(EpcisJob $job, EpcisDocument $document, bool $sync): void
    {
        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            return;
        }

        $runSync = $sync || Queue::getDefaultDriver() === 'sync';
        $processJob = new ProcessEpcisDocumentJob($tenant, (int) $document->getKey());

        try {
            $this->releaseUniqueLock->forLedger($job, $tenant);

            $job->forceFill([
                'received_at' => now(),
                'attempt_count' => ((int) $job->attempt_count) + 1,
            ])->save();

            if ($runSync) {
                EpcisCacheLock::lock($this->epcisProcessLockKey($document), 600)->block(30, function () use ($processJob): void {
                    $processJob->handle(app(EpcisIngestionService::class));
                });
            } else {
                ProcessEpcisDocumentJob::dispatch($tenant, (int) $document->getKey())
                    ->onQueue((string) config('tracepharma.epcis_jobs.queue', 'epcis'));
            }

            $this->logger->info($job, 'Stale queued job re-dispatched for inbound processing.');
        } catch (Throwable $e) {
            $this->logger->error($job, 'Stale queued job re-dispatch failed: '.$e->getMessage());
        }
    }

    private function epcisProcessLockKey(EpcisDocument $document): string
    {
        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');

        return 'epcis-process:'.$tenantId.':'.$document->getKey();
    }

    private function markEnqueueFailed(EpcisJob $job, string $message): void
    {
        $job->forceFill([
            'status' => EpcisJobStatus::Error,
            'finished_at' => now(),
            'error_message' => $message,
        ])->save();
    }
}
