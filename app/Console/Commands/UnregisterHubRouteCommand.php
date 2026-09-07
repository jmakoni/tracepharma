<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Integrations\ClaimTenantHubReceiverGln;
use App\Console\Commands\Concerns\ResolvesTenantConnections;
use Illuminate\Console\Command;
use RuntimeException;

class UnregisterHubRouteCommand extends Command
{
    use ResolvesTenantConnections;

    protected $signature = 'hub:unregister-route
        {tenant : Tenant id}
        {provider : tracepharma, systech, or unitrace}
        {gln : 13-digit receiver GLN}';

    protected $description = 'Remove an admin-claimed hub receiver-GLN route from a tenant.';

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

        $claim->unclaim($tenant, $provider, $gln);

        $this->info("Route removed: {$provider} / {$gln} for tenant [{$tenant->getKey()}] (admin claims only; connection-auto claims are removed by disabling hub routing on the connection).");

        return self::SUCCESS;
    }
}
