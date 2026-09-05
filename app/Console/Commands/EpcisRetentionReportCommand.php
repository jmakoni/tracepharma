<?php

namespace App\Console\Commands;

use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Support\Epcis\AuditPedigreePayloadRetention;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class EpcisRetentionReportCommand extends Command
{
    protected $signature = 'tracepharma:epcis-retention-report
        {--tenant= : Limit to a single tenant id; omit to scan all active tenants}
        {--check-pedigree-payloads : Also list commission/pack source docs with missing on-disk payloads}';

    protected $description = 'Report EPCIS retention posture (documents past cutoff; optional missing pedigree payloads). No deletes.';

    public function handle(AuditPedigreePayloadRetention $audit): int
    {
        $tenantId = $this->option('tenant');
        $checkPayloads = (bool) $this->option('check-pedigree-payloads');
        $failures = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');
        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        } elseif (tenancy()->initialized && tenant() instanceof Tenant && blank($tenantId)) {
            // Interactive single-tenant context without --tenant.
            $query->where('id', tenant()->getKey());
        }

        $tenants = $query->get();
        if ($tenants->isEmpty()) {
            $this->error('No matching active tenants.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            try {
                TenantRunner::run($tenant, function () use ($tenant, $audit, $checkPayloads, &$failures): void {
                    $years = (int) config('tracepharma.epcis.retention_years', 6);
                    $payloadYears = $audit->payloadRetentionYears();
                    $cutoff = now()->subYears($years);

                    $count = EpcisDocument::query()
                        ->where('received_at', '<', $cutoff)
                        ->count();

                    $this->info("Tenant: {$tenant->id} ({$tenant->name})");
                    $this->line("  Event retention years: {$years}");
                    $this->line("  Payload retention years (floor): {$payloadYears}");

                    if ($payloadYears < $years) {
                        $this->error('  Misconfiguration: payload_retention_years must be >= retention_years.');
                        $failures++;
                    }

                    $this->line('  Documents older than event retention: '.$count);

                    if ($checkPayloads) {
                        $missing = $audit->missingPedigreePayloads();
                        $this->line('  Missing pedigree payloads: '.count($missing));
                        if ($missing !== []) {
                            $failures++;
                            foreach (array_slice($missing, 0, 20) as $row) {
                                $this->warn(sprintf(
                                    '    doc=%d disk=%s reason=%s path=%s',
                                    $row['document_id'],
                                    $row['payload_disk'],
                                    $row['reason'],
                                    $row['payload_path'] !== '' ? $row['payload_path'] : '(empty)',
                                ));
                            }
                            if (count($missing) > 20) {
                                $this->warn('    … '.(count($missing) - 20).' more');
                            }
                        }
                    }
                });
            } catch (Throwable $exception) {
                $failures++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            }
        }

        $this->comment('Report only — no documents or payloads were deleted. Event archive never deletes payloads.');

        return $failures > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
