<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollPlatformSftpInbound;
use App\Services\Integrations\PlatformSftpInboundPoller;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\Integrations\PlatformSftpConfig;
use Illuminate\Console\Command;

class PollPlatformSftpInboundConnections extends Command
{
    protected $signature = 'epcis:poll-platform-sftp {--sync : Run the polls inline instead of dispatching jobs}';

    protected $description = 'Poll the platform-owned SFTP drop for each configured environment and hub-route inbound EPCIS files';

    public function handle(PlatformSftpConfig $config, PlatformSftpInboundPoller $poller): int
    {
        $count = 0;

        foreach (EpcisHubPlatformConfig::ENVIRONMENTS as $environment) {
            if (! $config->isConfigured($environment)) {
                continue;
            }

            if ($this->option('sync')) {
                $poller->poll($environment);
            } else {
                PollPlatformSftpInbound::dispatch($environment);
            }

            $count++;
        }

        $this->info("Dispatched {$count} platform SFTP poll job(s).");

        return self::SUCCESS;
    }
}
