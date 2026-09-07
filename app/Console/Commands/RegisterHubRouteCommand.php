<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Integrations\ClaimTenantHubReceiverGln;
use App\Console\Commands\Concerns\ResolvesTenantConnections;
use App\Models\InboundConnection;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use RuntimeException;

class RegisterHubRouteCommand extends Command
{
    use ResolvesTenantConnections;

    protected $signature = 'hub:register-route
        {tenant : Tenant id}
        {provider : tracepharma, systech, or unitrace}
        {gln : 13-digit receiver GLN}
        {--connection= : Inbound connection id to set as the default for this route}
        {--inactive : Register the route as inactive}
        {--allow-orphan : Allow GLNs that are not the company/site GLN of the tenant}';

    protected $description = 'Register (claim) a hub receiver-GLN route for a tenant.';

    public function handle(ClaimTenantHubReceiverGln $claim): int
    {
        try {
            $tenant = $this->resolveTenantOrFail((string) $this->argument('tenant'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $provider = strtolower(trim((string) $this->argument('provider')));
        $gln = trim((string) $this->argument('gln'));

        try {
            $route = $claim->claim(
                $tenant,
                $provider,
                $gln,
                active: ! (bool) $this->option('inactive'),
                allowOrphan: (bool) $this->option('allow-orphan'),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $connectionId = $this->option('connection');

        if (is_string($connectionId) && $connectionId !== '') {
            $connection = TenantRunner::run(
                $tenant,
                static fn () => InboundConnection::query()->find($connectionId),
            );

            if (! $connection instanceof InboundConnection) {
                $this->error("Inbound connection [{$connectionId}] not found on tenant [{$tenant->getKey()}]. Route registered without a default connection.");

                return self::FAILURE;
            }

            $route->forceFill(['default_inbound_connection_id' => $connection->getKey()])->save();
        }

        $this->info("Route registered: {$provider} / {$gln} → tenant [{$tenant->getKey()}] (route #{$route->getKey()}).");

        return self::SUCCESS;
    }
}
