<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Integrations\RegisterConnectionApprovalRequest;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use Illuminate\Console\Command;

class BackfillConnectionApprovalRequestsCommand extends Command
{
    protected $signature = 'tracepharma:backfill-connection-approval-requests {--tenant= : Limit the backfill to a single tenant id}';

    protected $description = 'Register pre-existing tenant connections on the central connection-requests list, mirroring their current approval status.';

    public function handle(RegisterConnectionApprovalRequest $registrar): int
    {
        $tenantId = $this->option('tenant');

        $tenants = Tenant::query()
            ->when(is_string($tenantId) && $tenantId !== '', fn ($query) => $query->where('id', $tenantId))
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants matched.');

            return self::SUCCESS;
        }

        $totalCreated = 0;
        $totalSkipped = 0;

        foreach ($tenants as $tenant) {
            $created = 0;
            $skipped = 0;

            $tenant->run(function () use ($registrar, &$created, &$skipped): void {
                foreach (InboundConnection::query()->cursor() as $connection) {
                    if ($registrar->registerGrandfathered($connection) === null) {
                        $skipped++;
                    } else {
                        $created++;
                    }
                }

                foreach (OutboundConnection::query()->cursor() as $connection) {
                    if ($registrar->registerGrandfathered($connection) === null) {
                        $skipped++;
                    } else {
                        $created++;
                    }
                }
            });

            $this->line("{$tenant->getKey()}: {$created} registered, {$skipped} already registered");

            $totalCreated += $created;
            $totalSkipped += $skipped;
        }

        $this->info("Backfill complete: {$totalCreated} registered, {$totalSkipped} already registered.");

        return self::SUCCESS;
    }
}
