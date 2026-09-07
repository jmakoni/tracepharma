<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EpcisHubRoute;
use Illuminate\Console\Command;

class ListHubRoutesCommand extends Command
{
    protected $signature = 'hub:routes
        {--tenant= : Limit to a single tenant id}
        {--provider= : tracepharma, systech, or unitrace}';

    protected $description = 'List hub receiver-GLN routes (which GLN routes to which tenant).';

    public function handle(): int
    {
        $routes = EpcisHubRoute::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
            ->when($this->option('provider'), fn ($q, $p) => $q->where('provider', strtolower(trim((string) $p))))
            ->orderBy('provider')
            ->orderBy('gln')
            ->get();

        if ($routes->isEmpty()) {
            $this->warn('No hub routes matched.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Tenant', 'Provider', 'GLN', 'Active', 'Claimed via', 'Default conn'],
            $routes->map(static fn (EpcisHubRoute $r): array => [
                $r->getKey(),
                $r->tenant_id,
                $r->provider,
                $r->gln,
                $r->is_active ? 'yes' : 'no',
                $r->claimed_via,
                $r->default_inbound_connection_id ?? '-',
            ])->all(),
        );

        $this->info("{$routes->count()} route(s).");

        return self::SUCCESS;
    }
}
