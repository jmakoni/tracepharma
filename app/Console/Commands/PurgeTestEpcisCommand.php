<?php

namespace App\Console\Commands;

use App\Actions\Epcis\PurgeTestEpcisDocuments;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class PurgeTestEpcisCommand extends Command
{
    private const DEFAULT_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    protected $signature = 'tracepharma:purge-test-epcis
        {--tenants=* : Tenant id(s) to purge; defaults to the demo2 tenant}
        {--dry-run : Report what would be deleted without deleting}
        {--force : Required to actually delete when --dry-run is not set}';

    protected $description = 'Hard-delete test-generated EPCIS documents (and orphaned inbound connections) leaked into a tenant database';

    public function handle(PurgeTestEpcisDocuments $purge): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force) {
            $this->error('Refusing to delete without --force. Pass --dry-run to preview matches, or --force to purge.');

            return self::FAILURE;
        }

        $tenants = $this->resolveTenants();

        if ($tenants->isEmpty()) {
            $this->info('No matching tenants found.');

            return self::SUCCESS;
        }

        $totals = [
            'tenants' => 0,
            'documents_deleted' => 0,
            'connections_deleted' => 0,
        ];

        foreach ($tenants as $tenant) {
            $result = TenantRunner::run($tenant, fn (): array => $purge->handle($dryRun));

            $totals['tenants']++;
            $totals['documents_deleted'] += $result['documents_deleted'];
            $totals['connections_deleted'] += $result['connections_deleted'];

            $prefix = $dryRun ? '[dry-run] ' : '';
            $this->line(
                "{$prefix}[{$tenant->id}] {$tenant->name}: documents_deleted={$result['documents_deleted']}, connections_deleted={$result['connections_deleted']}",
            );

            foreach ($result['dry_run_documents'] as $doc) {
                $this->line("  would delete document #{$doc['id']} {$doc['filename']} (direction={$doc['direction']}, status={$doc['status']})");
            }

            foreach ($result['dry_run_connections'] as $connection) {
                $this->line("  would delete connection #{$connection['id']} {$connection['name']}");
            }
        }

        $this->info(
            ($dryRun ? 'Would purge' : 'Purged')
            ." tenants={$totals['tenants']}, documents_deleted={$totals['documents_deleted']}, connections_deleted={$totals['connections_deleted']}",
        );

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Tenant>
     */
    private function resolveTenants(): Collection
    {
        /** @var list<string> $tenantIds */
        $tenantIds = $this->option('tenants');

        if ($tenantIds === []) {
            $tenantIds = [self::DEFAULT_TENANT_ID];
        }

        return Tenant::query()->whereIn('id', $tenantIds)->orderBy('id')->get();
    }
}
