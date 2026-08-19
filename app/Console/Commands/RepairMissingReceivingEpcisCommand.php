<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Receiving\RepairMissingReceivingEpcisEvents;
use App\Models\Tenant;
use Illuminate\Console\Command;

class RepairMissingReceivingEpcisCommand extends Command
{
    protected $signature = 'tracepharma:repair-missing-receiving-epcis
        {--tenant= : Tenant id (required unless already in tenancy)}
        {--session= : Optional receiving session id}
        {--dry-run : List sessions that would be repaired without writing}';

    protected $description = 'Regenerate receiving EPCIS for completed ASN/scan-first sessions missing receiving_epcis_document_id';

    public function handle(RepairMissingReceivingEpcisEvents $repair): int
    {
        $tenant = $this->resolveTenant();
        if ($tenant === null) {
            return self::FAILURE;
        }

        $alreadyOnTenant = tenancy()->initialized
            && tenant() instanceof Tenant
            && tenant()->getKey() === $tenant->getKey();

        if (! $alreadyOnTenant) {
            tenancy()->initialize($tenant);
        }

        try {
            $sessionId = filled($this->option('session'))
                ? (int) $this->option('session')
                : null;
            $dryRun = (bool) $this->option('dry-run');

            $summary = $repair->handle(
                sessionId: $sessionId,
                actorId: null,
                dryRun: $dryRun,
            );

            $this->info("Tenant: {$tenant->id} ({$tenant->name})");

            foreach ($summary['results'] as $row) {
                $doc = $row['document_id'] !== null ? " document=#{$row['document_id']}" : '';
                $msg = filled($row['message'] ?? null) ? " — {$row['message']}" : '';
                $this->line("session #{$row['session_id']} {$row['status']}{$doc}{$msg}");
            }

            if ($dryRun) {
                $this->info("Would repair sessions={$summary['attempted']}");
            } else {
                $this->info("Attempted={$summary['attempted']} repaired={$summary['repaired']} skipped={$summary['skipped']} failed={$summary['failed']}");
            }

            return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            if (! $alreadyOnTenant) {
                tenancy()->end();
            }
        }
    }

    private function resolveTenant(): ?Tenant
    {
        $tenantId = $this->option('tenant');

        if (filled($tenantId)) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                $this->error("Tenant [{$tenantId}] was not found.");
            }

            return $tenant;
        }

        if (tenancy()->initialized && tenant() instanceof Tenant) {
            return tenant();
        }

        $this->error('Pass --tenant= or run inside an initialized tenancy context.');

        return null;
    }
}
