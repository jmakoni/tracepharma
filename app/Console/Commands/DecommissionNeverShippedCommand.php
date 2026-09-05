<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Disposition\DecommissionNeverShippedEpcs;
use App\Models\Tenant;
use App\Support\Disposition\AssertDecommissionMassApproval;
use App\Support\Disposition\FindNeverShippedCommissionedEpcs;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * System actor for printed-never-shipped auto-decommission.
 * No tenant user. Chunks under the TP-406 remaining window capacity.
 */
class DecommissionNeverShippedCommand extends Command
{
    protected $signature = 'disposition:decommission-never-shipped
                            {--tenant= : Limit to a single tenant id}
                            {--dry-run : Report matches without emitting decommission events}';

    protected $description = 'Decommission commissioned EPCs that stayed unshipped past the configured hold';

    public function handle(
        DecommissionNeverShippedEpcs $action,
        FindNeverShippedCommissionedEpcs $finder,
        AssertDecommissionMassApproval $assertMassApproval,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $decommissioned = 0;
        $skipped = 0;
        $failed = 0;
        $tenantFailures = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $holdDays = max(1, (int) config('tracepharma.decommission.unshipped_hold_days', 30));
        $cutoff = now()->subDays($holdDays);

        $query->cursor()->each(function (Tenant $tenant) use (
            $action,
            $finder,
            $assertMassApproval,
            $dryRun,
            $cutoff,
            &$decommissioned,
            &$skipped,
            &$failed,
            &$tenantFailures,
        ): void {
            try {
                TenantRunner::run($tenant, function () use (
                    $tenant,
                    $action,
                    $finder,
                    $assertMassApproval,
                    $dryRun,
                    $cutoff,
                    &$decommissioned,
                    &$skipped,
                    &$failed,
                ): void {
                    if ($dryRun) {
                        $would = 0;
                        $held = 0;
                        foreach (EligibleReceiveSites::forOrganization()->get() as $site) {
                            $siteId = (int) $site->getKey();
                            $remaining = max(
                                0,
                                $assertMassApproval->threshold() - $assertMassApproval->recentDecommissionedEpcCount($siteId),
                            );
                            $candidates = $finder->atSite($siteId, $cutoff);
                            $would += min($remaining, count($candidates));
                            $held += max(0, count($candidates) - $remaining);
                        }

                        $this->line(sprintf(
                            '[dry-run] %s: would_decommission=%d deferred=%d',
                            $tenant->name,
                            $would,
                            $held,
                        ));

                        return;
                    }

                    $result = $action->handle();
                    $decommissioned += $result['decommissioned'];
                    $skipped += $result['skipped'];
                    $failed += $result['failed'];

                    if ($result['decommissioned'] > 0 || $result['failed'] > 0 || $result['skipped'] > 0) {
                        $this->line(sprintf(
                            '%s: decommissioned=%d skipped=%d failed=%d',
                            $tenant->name,
                            $result['decommissioned'],
                            $result['skipped'],
                            $result['failed'],
                        ));
                    }
                });
            } catch (Throwable $exception) {
                $tenantFailures++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        });

        $this->info(sprintf(
            'Never-shipped auto-decommission complete. decommissioned=%d skipped=%d failed=%d%s',
            $decommissioned,
            $skipped,
            $failed,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $tenantFailures > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
