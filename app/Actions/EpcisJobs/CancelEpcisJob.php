<?php

declare(strict_types=1);

namespace App\Actions\EpcisJobs;

use App\Enums\EpcisJobStatus;
use App\Models\EpcisJob;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\EpcisJobs\EpcisJobLogger;
use App\Support\EpcisJobs\EpcisJobSla;
use App\Support\EpcisJobs\ReleaseEpcisJobUniqueLock;
use RuntimeException;

final class CancelEpcisJob
{
    public function __construct(
        private readonly EpcisJobLogger $logger,
        private readonly ReleaseEpcisJobUniqueLock $releaseUniqueLock,
    ) {}

    public function handle(EpcisJob $job): EpcisJob
    {
        if (! JobRoleAccess::allowsForActor(Permissions::NavIntegrations, auth()->user())) {
            throw new RuntimeException('Integrations are not authorized for your job role.');
        }

        $job = $job->fresh() ?? $job;

        if (! EpcisJobSla::canCancel($job)) {
            throw new RuntimeException(
                'Only queued jobs can be cancelled, or stuck sending/processing jobs past the worker timeout.',
            );
        }

        $this->releaseUniqueLock->forLedger($job);

        $wasQueued = $job->status === EpcisJobStatus::Queued;

        $job->forceFill([
            'status' => EpcisJobStatus::Cancelled,
            'finished_at' => now(),
        ])->save();

        $document = $job->document;
        if ($document !== null && $document->direction === 'outbound') {
            $document->forceFill([
                'transmission_status' => 'skipped',
            ])->save();
        }

        $this->logger->info(
            $job,
            $wasQueued
                ? 'Job cancelled before processing.'
                : 'Stuck job cancelled after worker timeout.',
        );

        return $job->fresh() ?? $job;
    }
}
