<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RotateHubTokenCommand extends Command
{
    protected $signature = 'hub:rotate-token
        {environment : demo, stage, or prod}
        {--show : Print the new token to the console (otherwise only a confirmation is shown)}';

    protected $description = 'Rotate the hub token for an environment. The previous token stays valid for the 24h grace window.';

    public function handle(EpcisHubPlatformConfig $config): int
    {
        $environment = strtolower(trim((string) $this->argument('environment')));

        try {
            $token = $config->rotateHubToken($environment);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $graceHours = EpcisHubPlatformConfig::TOKEN_ROTATION_GRACE_HOURS;

        if ((bool) $this->option('show')) {
            $this->info("New [{$environment}] hub token: {$token}");
        } else {
            $this->info("Hub token for [{$environment}] rotated (pass --show to print it).");
        }

        $this->line("The previous token stays accepted for {$graceHours}h. Platform-egress connections pick up the new token automatically; partners with static copies must update before the grace window ends.");

        return self::SUCCESS;
    }
}
