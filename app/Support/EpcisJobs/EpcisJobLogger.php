<?php

declare(strict_types=1);

namespace App\Support\EpcisJobs;

use App\Models\EpcisJob;
use App\Models\EpcisJobMessage;

final class EpcisJobLogger
{
    public function info(EpcisJob $job, string $message): void
    {
        $this->write($job, 'info', $message);
    }

    public function warning(EpcisJob $job, string $message): void
    {
        $this->write($job, 'warning', $message);
    }

    public function error(EpcisJob $job, string $message): void
    {
        $this->write($job, 'error', $message);
    }

    private function write(EpcisJob $job, string $level, string $message): void
    {
        EpcisJobMessage::query()->create([
            'epcis_job_id' => $job->getKey(),
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}
