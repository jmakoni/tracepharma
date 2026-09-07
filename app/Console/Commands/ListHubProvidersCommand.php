<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Illuminate\Console\Command;

class ListHubProvidersCommand extends Command
{
    protected $signature = 'hub:providers {environment? : demo, stage, or prod (default: all)}';

    protected $description = 'List enabled hub providers per environment.';

    public function handle(EpcisHubPlatformConfig $config): int
    {
        $environment = $this->argument('environment');

        $environments = is_string($environment) && $environment !== ''
            ? [strtolower(trim($environment))]
            : EpcisHubPlatformConfig::ENVIRONMENTS;

        $rows = [];

        foreach ($environments as $env) {
            if (! in_array($env, EpcisHubPlatformConfig::ENVIRONMENTS, true)) {
                $this->error("Unsupported environment [{$env}].");

                return self::FAILURE;
            }

            $providers = $config->enabledProviders($env);

            $rows[] = [
                $env,
                $config->host($env),
                $providers === [] ? '(none)' : implode(', ', $providers),
                $config->hubToken($env) !== null ? 'set' : '(missing)',
            ];
        }

        $this->table(['Environment', 'Host', 'Providers', 'Hub token'], $rows);

        return self::SUCCESS;
    }
}
