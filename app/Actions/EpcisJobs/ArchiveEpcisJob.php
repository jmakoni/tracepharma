<?php

declare(strict_types=1);

namespace App\Actions\EpcisJobs;

use App\Models\EpcisJob;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\EpcisJobs\EpcisJobLogger;
use RuntimeException;

final class ArchiveEpcisJob
{
    public function __construct(
        private readonly EpcisJobLogger $logger,
    ) {}

    public function handle(EpcisJob $job): EpcisJob
    {
        if (! JobRoleAccess::allowsAny(Permissions::NavIntegrations, Permissions::NavExceptions)) {
            throw new RuntimeException('Integrations or Exceptions are not authorized for your job role.');
        }

        $job = $job->fresh() ?? $job;

        if (! $job->status?->isTerminal()) {
            throw new RuntimeException('Only terminal jobs can be archived.');
        }

        if ($job->archived_at !== null) {
            return $job;
        }

        $job->forceFill([
            'archived_at' => now(),
        ])->save();

        $this->logger->info($job, 'Job archived.');

        return $job->fresh() ?? $job;
    }
}
