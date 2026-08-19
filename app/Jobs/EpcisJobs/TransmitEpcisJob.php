<?php

declare(strict_types=1);

namespace App\Jobs\EpcisJobs;

use App\Enums\EpcisJobStatus;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\Tenant;
use App\Services\Epcis\ConnectionOutboundEpcisTransmitter;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Support\EpcisJobs\EpcisJobLogger;
use App\Support\EpcisJobs\EpcisJobStats;
use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class TransmitEpcisJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    /** Technical attempts for transient failures; business errors are marked without rethrow. */
    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public Tenant $tenant,
        public int $epcisJobId,
        public bool $forceRequeue = false,
        public ?int $epcisDocumentId = null,
    ) {}

    public function uniqueId(): string
    {
        $id = (string) $this->tenant->getKey().':epcis-job:'.$this->epcisJobId;

        if ($this->epcisDocumentId !== null) {
            $id .= ':document:'.$this->epcisDocumentId;
        }

        return $id;
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
        return [15, 60];
    }

    public function handle(
        OutboundEpcisTransmitter $transmitter,
        EpcisJobLogger $logger,
        EpcisJobStats $stats,
    ): void {
        if (! TenantAccess::isActive($this->tenant)) {
            $this->tenant->run(function () use ($logger): void {
                $job = EpcisJob::query()->find($this->epcisJobId);

                if ($job === null) {
                    return;
                }

                $this->markError($job, $logger, 'Tenant is suspended; outbound transmission skipped.');
            });

            return;
        }

        if (TenantKillSwitches::forTenant($this->tenant)->outboundEpcisKilled()) {
            $message = TenantKillSwitches::blockedMessage(TenantKillSwitches::OUTBOUND_EPCIS);

            $this->tenant->run(function () use ($logger, $message): void {
                $job = EpcisJob::query()->find($this->epcisJobId);

                if ($job === null) {
                    return;
                }

                $this->markError($job, $logger, $message);
            });

            return;
        }

        $deferWithBackoff = false;

        $this->tenant->run(function () use ($transmitter, $logger, $stats, &$deferWithBackoff): void {
            $job = EpcisJob::query()->find($this->epcisJobId);

            if ($job === null) {
                return;
            }

            if ($job->status === EpcisJobStatus::Cancelled) {
                $logger->info($job, 'Job was cancelled; skipping transmit.');

                return;
            }

            if ($job->status === EpcisJobStatus::Error) {
                $logger->info($job, 'Job is in error; skipping transmit.');

                return;
            }

            if ($job->status === EpcisJobStatus::Complete) {
                return;
            }

            $started = now();
            $job->forceFill([
                'status' => EpcisJobStatus::Sending,
                'started_at' => $job->started_at ?? $started,
                'attempt_count' => ((int) $job->attempt_count) + 1,
            ])->save();

            $document = $job->document;

            if ($document === null) {
                $this->markError($job, $logger, 'EPCIS document missing.');

                return;
            }

            $document = $document->fresh() ?? $document;

            if ($document->transmission_status === 'sent' && ! $this->forceRequeue) {
                $finished = now();
                $ms = (int) max(0, $started->diffInMilliseconds($finished));
                $job->forceFill([
                    'status' => EpcisJobStatus::Complete,
                    'finished_at' => $finished,
                    'processing_time_ms' => $ms,
                    'outbound_connection_id' => $document->outbound_connection_id,
                    'error_message' => null,
                    'stats_json' => $stats->forDocument($document, $ms),
                ])->save();
                $logger->info($job, 'Transmission already sent; skipping duplicate transmit.');

                return;
            }

            if (
                ! $this->forceRequeue
                && $document->transmission_status === 'sending'
                && $document->sent_at === null
            ) {
                if ($transmitter instanceof ConnectionOutboundEpcisTransmitter
                    && $transmitter->recoverSentFromPersistedEvidence($document)) {
                    $document = $document->fresh() ?? $document;
                    $finished = now();
                    $ms = (int) max(0, $started->diffInMilliseconds($finished));
                    $job->forceFill([
                        'status' => EpcisJobStatus::Complete,
                        'finished_at' => $finished,
                        'processing_time_ms' => $ms,
                        'outbound_connection_id' => $document->outbound_connection_id,
                        'error_message' => null,
                        'stats_json' => $stats->forDocument($document, $ms),
                    ])->save();
                    $logger->info($job, 'Recovered outbound transmission from persisted send evidence.');

                    return;
                }

                if ($transmitter instanceof ConnectionOutboundEpcisTransmitter
                    && $transmitter->hasRecentTransmitHeartbeat($document)) {
                    $job->forceFill([
                        'status' => EpcisJobStatus::Queued,
                        'started_at' => null,
                    ])->save();
                    $logger->info($job, 'Outbound transmission appears in flight; deferring with backoff.');
                    $deferWithBackoff = true;

                    return;
                }
            }

            if ($document->transmission_status !== 'sending') {
                EpcisDocument::query()
                    ->whereKey($document->getKey())
                    ->update(['transmission_status' => 'sending']);
            }

            $document = $document->fresh() ?? $document;

            $logger->info($job, 'Starting outbound transmission.');

            try {
                $transmitter->transmit($document->fresh() ?? $document, $this->forceRequeue);
            } catch (Throwable $e) {
                if ($this->attempts() < $this->tries && $this->isTransient($e)) {
                    throw $e;
                }

                $this->markError($job, $logger, $e->getMessage());

                return;
            }

            $job = $job->fresh() ?? $job;

            if ($job->status?->isTerminal()) {
                $logger->info($job, 'Job reached a terminal state during transmission; skipping status update.');

                return;
            }

            $document = $document->fresh() ?? $document;
            $status = (string) ($document->transmission_status ?? '');

            if ($status === 'sent') {
                $finished = now();
                $ms = (int) max(0, $started->diffInMilliseconds($finished));
                $job->forceFill([
                    'status' => EpcisJobStatus::Complete,
                    'finished_at' => $finished,
                    'processing_time_ms' => $ms,
                    'outbound_connection_id' => $document->outbound_connection_id,
                    'error_message' => null,
                    'stats_json' => $stats->forDocument($document, $ms),
                ])->save();
                $logger->info($job, 'Transmission complete.');

                return;
            }

            if ($status === 'skipped') {
                $job->forceFill([
                    'status' => EpcisJobStatus::Cancelled,
                    'finished_at' => now(),
                    'error_message' => $document->error_message,
                ])->save();
                $logger->warning($job, 'Transmission skipped (no payload or connection).');

                return;
            }

            $this->markError($job, $logger, (string) ($document->error_message ?: 'Transmission failed.'));
        });

        if ($deferWithBackoff ?? false) {
            $backoff = $this->backoff();
            $delay = $backoff[min(max(0, $this->attempts() - 1), count($backoff) - 1)] ?? 15;
            $this->release($delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->tenant->run(function () use ($exception): void {
            $job = EpcisJob::query()->find($this->epcisJobId);

            if ($job === null || $job->status?->isTerminal()) {
                return;
            }

            app(EpcisJobLogger::class)->error(
                $job,
                'Job failed: '.($exception?->getMessage() ?? 'unknown error'),
            );

            $job->forceFill([
                'status' => EpcisJobStatus::Error,
                'finished_at' => now(),
                'error_message' => $exception?->getMessage(),
            ])->save();

            $job->document?->forceFill([
                'transmission_status' => 'failed',
                'error_message' => $exception?->getMessage(),
            ])->save();
        });
    }

    private function markError(EpcisJob $job, EpcisJobLogger $logger, string $message): void
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

        $job->document?->forceFill([
            'transmission_status' => 'failed',
            'error_message' => $message,
        ])->save();

        $logger->error($job, $message);
    }

    private function isTransient(Throwable $e): bool
    {
        if ($e instanceof \League\Flysystem\UnableToCheckExistence
            || $e instanceof \League\Flysystem\UnableToReadFile) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'unable to check existence')
            || str_contains($message, 'unable to read file');
    }
}
