<?php

declare(strict_types=1);

namespace App\Support\EpcisJobs;

use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Models\EpcisJob;

final class EpcisJobSla
{
    public const GRACE_SECONDS = 60;

    public static function staleQueuedSeconds(): int
    {
        return max(60, (int) config('tracepharma.epcis_jobs.stale_queued_seconds', 900));
    }

    public static function isStaleQueued(EpcisJob $job): bool
    {
        if ($job->status !== EpcisJobStatus::Queued || $job->received_at === null) {
            return false;
        }

        return now()->greaterThanOrEqualTo(
            $job->received_at->copy()->addSeconds(self::staleQueuedSeconds()),
        );
    }

    public static function workerTimeoutSeconds(EpcisJob $job): int
    {
        return $job->kind === EpcisJobKind::InboundProcess ? 600 : 300;
    }

    public static function isPastSla(EpcisJob $job): bool
    {
        return self::isPastSendingOrProcessingSla($job);
    }

    public static function isPastSendingOrProcessingSla(EpcisJob $job): bool
    {
        if (! in_array($job->status, [EpcisJobStatus::Sending, EpcisJobStatus::Processing], true)) {
            return false;
        }

        if ($job->started_at === null) {
            return false;
        }

        $deadline = $job->started_at->copy()->addSeconds(
            self::workerTimeoutSeconds($job) + self::GRACE_SECONDS,
        );

        return now()->greaterThanOrEqualTo($deadline);
    }

    public static function canCancel(EpcisJob $job): bool
    {
        if ($job->status === EpcisJobStatus::Queued) {
            return true;
        }

        return self::isPastSla($job);
    }

    public static function canForceFail(EpcisJob $job): bool
    {
        return self::isPastSla($job);
    }
}
