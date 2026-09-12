<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Illuminate\Console\Command;
use InvalidArgumentException;

class DisableHubProviderCommand extends Command
{
    protected $signature = 'hub:disable-provider
        {environment : demo, stage, or prod}
        {provider : e.g. tracepharma, systech, unitrace}';

    protected $description = 'Disable a hub provider for an environment (hub stops accepting documents for it).';

    public function handle(EpcisHubPlatformConfig $config): int
    {
        $environment = strtolower(trim((string) $this->argument('environment')));
        $provider = strtolower(trim((string) $this->argument('provider')));

        try {
            $providers = array_values(array_filter(
                $config->enabledProviders($environment),
                static fn (string $p): bool => $p !== $provider,
            ));

            $config->setProviders($environment, $providers);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Provider [{$provider}] disabled for [{$environment}]. Enabled: ".(implode(', ', $config->enabledProviders($environment)) ?: '(none)'));

        return self::SUCCESS;
    }
}
