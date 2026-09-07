<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenantConnections;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use RuntimeException;

class SuspendConnectionCommand extends Command
{
    use ResolvesTenantConnections;

    protected $signature = 'connections:suspend
        {tenant : Tenant id}
        {connection : Connection id}
        {--direction=inbound : inbound or outbound}
        {--reason= : Reason recorded in the tenant activity log}';

    protected $description = 'Disable a tenant connection (is_active=false) with an audited reason. Traffic stops immediately.';

    public function handle(): int
    {
        try {
            $tenant = $this->resolveTenantOrFail((string) $this->argument('tenant'));
            [$connection, $direction] = $this->resolveConnectionOrFail(
                $tenant,
                $this->argument('connection'),
                (string) $this->option('direction'),
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $reason = trim((string) ($this->option('reason') ?? ''));

        TenantRunner::run($tenant, static function () use ($connection, $reason): void {
            $connection->forceFill(['is_active' => false])->save();

            activity()
                ->performedOn($connection)
                ->withProperties(['reason' => $reason !== '' ? $reason : null])
                ->log('connection suspended via CLI');
        });

        $this->info("Suspended {$direction} connection [{$connection->getKey()}] ({$connection->name}) on tenant [{$tenant->getKey()}].");

        return self::SUCCESS;
    }
}
