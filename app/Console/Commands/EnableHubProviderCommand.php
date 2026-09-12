<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Illuminate\Console\Command;
use InvalidArgumentException;

class EnableHubProviderCommand extends Command
{
    protected $signature = 'hub:enable-provider
        {environment : demo, stage, or prod}
        {provider : e.g. tracepharma, systech, unitrace}';

    protected $description = 'Enable a hub provider for an environment (hub starts accepting documents for it).';

    public function handle(EpcisHubPlatformConfig $config): int
    {
        $environment = strtolower(trim((string) $this->argument('environment')));
        $provider = strtolower(trim((string) $this->argument('provider')));

        try {
            $providers = $config->enabledProviders($environment);

            if (! in_array($provider, $providers, true)) {
                $providers[] = $provider;
                $config->setProviders($environment, $providers);
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Provider [{$provider}] enabled for [{$environment}]. Enabled: ".implode(', ', $config->enabledProviders($environment)));

        return self::SUCCESS;
    }
}
