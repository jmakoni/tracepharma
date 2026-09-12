<?php

declare(strict_types=1);

namespace App\Actions\EpcisJobs;

use App\Enums\EpcisJobStatus;
use App\Jobs\EpcisJobs\TransmitEpcisJob;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\Tenant;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Epcis\EpcisCacheLock;
use App\Support\EpcisJobs\EpcisJobLogger;
use App\Support\EpcisJobs\EpcisJobSla;
use App\Support\EpcisJobs\ReleaseEpcisJobUniqueLock;
use App\Support\EpcisJobs\ResolveEpcisJobSources;
use App\Support\Tenancy\TenantKillSwitches;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class EnqueueEpcisJob
{
    public function __construct(
        private readonly ResolveEpcisJobSources $resolveSources,
        private readonly EpcisJobLogger $logger,
        private readonly ReleaseEpcisJobUniqueLock $releaseUniqueLock,
    ) {}

    public function handle(EpcisDocument $document, ?int $requestedBy = null, bool $forceRequeue = false): EpcisJob
    {
        if (! JobRoleAccess::allowsForActor(Permissions::NavIntegrations, auth()->user())) {
            throw new RuntimeException('Integrations are not authorized for your job role.');
        }

        if (TenantKillSwitches::forTenant()->outboundEpcisKilled()) {
            throw new RuntimeException(
                TenantKillSwitches::blockedMessage(TenantKillSwitches::OUTBOUND_EPCIS),
            );
        }

        $document = $document->fresh() ?? $document;

        if ($document->direction !== 'outbound') {
            throw new RuntimeException('Only outbound EPCIS documents can be enqueued in Phase 1.');
        }

        $sources = $this->resolveSources->fromDocument($document);

        if ($sources === null || ! $sources['kind']->isPhase1Outbound()) {
            throw new RuntimeException('Document is not a supported Phase 1 authored outbound kind.');
        }

        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');
        $lockKey = 'epcis-enqueue:'.$tenantId.':'.$document->getKey();

        return EpcisCacheLock::lock($lockKey, 30)->block(10, function () use ($document, $sources, $requestedBy, $forceRequeue): EpcisJob {
            $created = false;

            $job = DB::transaction(function () use ($document, $sources, $requestedBy, &$created): EpcisJob {
                EpcisDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

                $active = EpcisJob::query()
                    ->where('epcis_document_id', $document->getKey())
                    ->whereIn('status', [EpcisJobStatus::Queued->value, EpcisJobStatus::Sending->value])
                    ->whereNull('archived_at')
                    ->first();

                if ($active !== null) {
                    return $active;
                }

                $receipt = str_replace('-', '', (string) Str::uuid());

                $job = EpcisJob::query()->create([
                    'receipt' => $receipt,
                    'kind' => $sources['kind'],
                    'status' => EpcisJobStatus::Queued,
                    'epcis_document_id' => $document->getKey(),
                    'outbound_shipping_session_id' => $sources['outbound_shipping_session_id'],
                    'receiving_session_id' => $sources['receiving_session_id'],
                    'transferring_session_id' => $sources['transferring_session_id'],
                    'sscc_label_batch_id' => $sources['sscc_label_batch_id'],
                    'outbound_connection_id' => $document->outbound_connection_id,
                    'ship_from_site_id' => $sources['ship_from_site_id'],
                    'requested_by' => $requestedBy ?? auth()->id(),
                    'original_filename' => $document->original_filename,
                    'received_at' => now(),
                    'attempt_count' => 0,
                ]);

                $document->forceFill([
                    'transmission_status' => 'queued',
                ])->save();

                $this->logger->info($job, 'Job queued for outbound transmission.');
                $created = true;

                return $job;
            });

            if (! $created) {
                $staleJob = $job->fresh() ?? $job;
                if (EpcisJobSla::isStaleQueued($staleJob)) {
                    $this->redispatchStaleOutboundJob($staleJob, $document, $forceRequeue);
                }

                return $job->fresh() ?? $job;
            }

            $tenant = tenant();
            if (! $tenant instanceof Tenant) {
                $this->markEnqueueFailed($job, $document, 'Tenant context required to dispatch EPCIS job.');

                throw new RuntimeException('Tenant context required to dispatch EPCIS job.');
            }

            try {
                TransmitEpcisJob::dispatch($tenant, (int) $job->getKey(), $forceRequeue, (int) $document->getKey())
                    ->onQueue((string) config('tracepharma.epcis_jobs.queue', 'epcis'));
            } catch (Throwable $e) {
                $this->markEnqueueFailed($job, $document, $e->getMessage());

                throw $e;
            }

            return $job->fresh() ?? $job;
        });
    }

    private function redispatchStaleOutboundJob(
        EpcisJob $job,
        EpcisDocument $document,
        bool $forceRequeue,
    ): void {
        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            return;
        }

        try {
            $this->releaseUniqueLock->forLedger($job, $tenant);

            $job->forceFill([
                'received_at' => now(),
                'attempt_count' => ((int) $job->attempt_count) + 1,
            ])->save();

            TransmitEpcisJob::dispatch($tenant, (int) $job->getKey(), $forceRequeue, (int) $document->getKey())
                ->onQueue((string) config('tracepharma.epcis_jobs.queue', 'epcis'));

            $this->logger->info($job, 'Stale queued job re-dispatched for outbound transmission.');
        } catch (Throwable $e) {
            $this->logger->error($job, 'Stale queued job re-dispatch failed: '.$e->getMessage());
        }
    }

    private function markEnqueueFailed(EpcisJob $job, EpcisDocument $document, string $message): void
    {
        $job->forceFill([
            'status' => EpcisJobStatus::Error,
            'finished_at' => now(),
            'error_message' => $message,
        ])->save();

        $document->forceFill([
            'transmission_status' => 'failed',
            'error_message' => $message,
        ])->save();
    }
}
