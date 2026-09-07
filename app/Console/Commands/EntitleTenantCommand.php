<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenantConnections;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Illuminate\Console\Command;
use RuntimeException;

class EntitleTenantCommand extends Command
{
    use ResolvesTenantConnections;

    protected $signature = 'tenant:entitle
        {tenant : Tenant id}
        {--inbound-env= : Hub inbound environment (demo, stage, prod)}
        {--add-provider= : Add a hub provider entitlement (repeatable list via comma)}
        {--remove-provider= : Remove a hub provider entitlement (comma-separated)}';

    protected $description = 'Manage a tenant\'s hub entitlement: inbound environment and enabled hub providers.';

    public function handle(EpcisHubPlatformConfig $config): int
    {
        try {
            $tenant = $this->resolveTenantOrFail((string) $this->argument('tenant'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dirty = false;

        $inboundEnv = $this->option('inbound-env');

        if (is_string($inboundEnv) && $inboundEnv !== '') {
            $inboundEnv = strtolower(trim($inboundEnv));

            if (! in_array($inboundEnv, EpcisHubPlatformConfig::ENVIRONMENTS, true)) {
                $this->error('Inbound environment must be one of: '.implode(', ', EpcisHubPlatformConfig::ENVIRONMENTS));

                return self::FAILURE;
            }

            $tenant->inbound_environment = $inboundEnv;
            $dirty = true;
        }

        $providers = is_array($tenant->hub_providers) ? $tenant->hub_providers : [];
        $providers = array_values(array_filter(array_map(
            static fn ($p) => is_string($p) ? strtolower(trim($p)) : '',
            $providers,
        ), static fn (string $p): bool => $p !== ''));

        foreach ($this->csvOption('add-provider') as $provider) {
            if (! in_array($provider, $providers, true)) {
                $providers[] = $provider;
                $dirty = true;
            }
        }

        $remove = $this->csvOption('remove-provider');

        if ($remove !== []) {
            $providers = array_values(array_filter(
                $providers,
                static fn (string $p): bool => ! in_array($p, $remove, true),
            ));
            $dirty = true;
        }

        if (! $dirty) {
            $this->warn('Nothing to change. Pass --inbound-env, --add-provider, or --remove-provider.');
            $this->line('Current: inbound_environment='.var_export($tenant->inbound_environment, true).', hub_providers=['.implode(', ', $providers).']');

            return self::SUCCESS;
        }

        $tenant->hub_providers = $providers;
        $tenant->save();

        $this->info("Tenant [{$tenant->getKey()}] entitlement updated: inbound_environment={$tenant->inbound_environment}, hub_providers=[".implode(', ', $providers).']');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function csvOption(string $name): array
    {
        $raw = $this->option($name);

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $p): string => strtolower(trim($p)),
            explode(',', $raw),
        ), static fn (string $p): bool => $p !== ''));
    }
}
