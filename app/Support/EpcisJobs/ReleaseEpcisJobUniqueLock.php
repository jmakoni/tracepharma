<?php

declare(strict_types=1);

namespace App\Support\EpcisJobs;

use App\Enums\EpcisJobKind;
use App\Jobs\EpcisJobs\TransmitEpcisJob;
use App\Jobs\ProcessEpcisDocumentJob;
use App\Models\EpcisJob;
use App\Models\Tenant;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Release ShouldBeUnique and WithoutOverlapping queue locks when an operator cancels
 * or force-fails a ledger job so requeue is not blocked by a dead worker.
 */
final class ReleaseEpcisJobUniqueLock
{
    public function __construct(
        private readonly Cache $cache,
    ) {}

    public function forLedger(EpcisJob $job, ?Tenant $tenant = null): void
    {
        $tenant ??= tenant();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $queueJob = $this->queueJobForLedger($job, $tenant);

        app(UniqueLock::class)->release($queueJob);
        $this->releaseOverlapLock($queueJob);
    }

    private function queueJobForLedger(EpcisJob $job, Tenant $tenant): TransmitEpcisJob|ProcessEpcisDocumentJob
    {
        return match ($job->kind) {
            EpcisJobKind::InboundProcess => new ProcessEpcisDocumentJob(
                $tenant,
                (int) $job->epcis_document_id,
            ),
            default => new TransmitEpcisJob(
                $tenant,
                (int) $job->getKey(),
                false,
                $job->epcis_document_id !== null ? (int) $job->epcis_document_id : null,
            ),
        };
    }

    private function releaseOverlapLock(TransmitEpcisJob|ProcessEpcisDocumentJob $queueJob): void
    {
        $middleware = new WithoutOverlapping($queueJob->uniqueId());
        $lockKey = $middleware->getLockKey($queueJob);

        $this->cache->lock($lockKey)->forceRelease();
    }
}
