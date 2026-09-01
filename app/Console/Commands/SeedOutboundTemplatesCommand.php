<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Outbound\EnsureSystemOutboundTemplates;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SeedOutboundTemplatesCommand extends Command
{
    protected $signature = 'tenants:seed-outbound-templates
                            {--tenants=* : Tenant IDs (default: all)}';

    protected $description = 'Ensure inactive Email + Client portal system outbound connection templates exist for tenant(s)';

    public function handle(EnsureSystemOutboundTemplates $ensure): int
    {
        $tenantIds = array_values(array_filter((array) $this->option('tenants')));

        $query = Tenant::query()->orderBy('id');
        if ($tenantIds !== []) {
            $query->whereIn('id', $tenantIds);
        }

        $tenants = $query->get();
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            $result = $tenant->run(fn (): array => $ensure->handle());
            $this->info(sprintf(
                '[%s] created=%d existing=%d',
                $tenant->getTenantKey(),
                $result['created'],
                $result['existing'],
            ));
        }

        return self::SUCCESS;
    }
}
