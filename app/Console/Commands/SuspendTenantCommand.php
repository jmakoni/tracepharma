<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tenants\SuspendTenant;
use App\Console\Commands\Concerns\ResolvesTenantConnections;
use Illuminate\Console\Command;
use RuntimeException;

class SuspendTenantCommand extends Command
{
    use ResolvesTenantConnections;

    protected $signature = 'tenant:suspend
        {tenant : Tenant ID}
        {--reason= : Required suspension reason shown in audits}';

    protected $description = 'Suspend a tenant (cascades to the pair sibling) with an audited reason';

    public function handle(SuspendTenant $suspend): int
    {
        $reason = trim((string) ($this->option('reason') ?? ''));

        if ($reason === '') {
            $this->error('The --reason option is required.');

            return self::FAILURE;
        }

        try {
            $tenant = $this->resolveTenantOrFail((string) $this->argument('tenant'));
            $suspend->handle($tenant, $reason);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant [{$tenant->name}] suspended.");

        return self::SUCCESS;
    }
}
