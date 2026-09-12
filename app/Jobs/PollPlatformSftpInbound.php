<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Integrations\PlatformSftpInboundPoller;
use App\Support\Integrations\PlatformSftpConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class PollPlatformSftpInbound implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(
        public readonly string $environment,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('poll-platform-sftp:'.$this->environment))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(PlatformSftpInboundPoller $poller, PlatformSftpConfig $config): void
    {
        if (! $config->isConfigured($this->environment)) {
            return;
        }

        $poller->poll($this->environment);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PollPlatformSftpInbound failed.', [
            'environment' => $this->environment,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['platform:sftp', 'env:'.$this->environment];
    }
}
