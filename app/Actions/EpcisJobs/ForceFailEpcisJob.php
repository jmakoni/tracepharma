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

final class ForceFailEpcisJob
{
    public function __construct(
        private readonly EpcisJobLogger $logger,
        private readonly ReleaseEpcisJobUniqueLock $releaseUniqueLock,
    ) {}

    public function handle(EpcisJob $job, ?string $reason = null): EpcisJob
    {
        if (! JobRoleAccess::allowsForActor(Permissions::NavIntegrations, auth()->user())) {
            throw new RuntimeException('Integrations are not authorized for your job role.');
        }

        $job = $job->fresh() ?? $job;

        if (! EpcisJobSla::canForceFail($job)) {
            throw new RuntimeException(
                'Only sending or processing jobs past the worker timeout can be force-failed.',
            );
        }

        $this->releaseUniqueLock->forLedger($job);

        $message = $reason ?? 'Force-failed after worker timeout exceeded.';

        $job->forceFill([
            'status' => EpcisJobStatus::Error,
            'finished_at' => now(),
            'error_message' => $message,
        ])->save();

        $document = $job->document;
        if ($document !== null) {
            if ($document->direction === 'outbound') {
                $document->forceFill([
                    'transmission_status' => 'failed',
                    'error_message' => $message,
                ])->save();
            } elseif ($document->direction === 'inbound' && in_array($document->status, ['received', 'parsing'], true)) {
                $document->forceFill([
                    'status' => 'error',
                    'error_message' => $message,
                ])->save();
            }
        }

        $this->logger->error($job, $message);

        return $job->fresh() ?? $job;
    }
}
