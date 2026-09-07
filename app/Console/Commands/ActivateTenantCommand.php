<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenants\ActivateTenant;
use App\Console\Commands\Concerns\ResolvesTenantConnections;
use Illuminate\Console\Command;
use RuntimeException;

class ActivateTenantCommand extends Command
{
    use ResolvesTenantConnections;

    protected $signature = 'tenant:activate
        {tenant : Tenant ID}';

    protected $description = 'Reactivate a suspended tenant (cascades to the pair sibling)';

    public function handle(ActivateTenant $activate): int
    {
        try {
            $tenant = $this->resolveTenantOrFail((string) $this->argument('tenant'));
            $activate->handle($tenant);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant [{$tenant->name}] activated.");

        return self::SUCCESS;
    }
}
