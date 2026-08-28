<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Epcis\RecordOperationalEpcisCatalogSignal;
use App\Models\Epcis\TransmissionMdn;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Emit MISSING_MDN / LATE_MDN catalog exceptions for pending AS2 transmission MDNs
 * that have aged past configured SLA windows.
 */
class EmitPendingMdnCatalogSignalsCommand extends Command
{
    protected $signature = 'epcis:emit-pending-mdn-signals
                            {--tenant= : Limit to a single tenant id}
                            {--dry-run : Report matches without recording exceptions}';

    protected $description = 'Record MISSING_MDN / LATE_MDN catalog signals for pending AS2 MDNs past SLA';

    public function handle(RecordOperationalEpcisCatalogSignal $catalogSignal): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $emitted = 0;
        $skipped = 0;
        $failed = 0;

        $missingAfterHours = max(1, (int) config('tracepharma.as2_mdn.missing_after_hours', 24));
        $lateAfterHours = max($missingAfterHours + 1, (int) config('tracepharma.as2_mdn.late_after_hours', 72));

        $missingBefore = now()->subHours($missingAfterHours);
        $lateBefore = now()->subHours($lateAfterHours);

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use (
            $catalogSignal,
            $dryRun,
            $missingBefore,
            $lateBefore,
            &$emitted,
            &$skipped,
            &$failed,
        ): void {
            try {
                $tenant->run(function () use (
                    $tenant,
                    $catalogSignal,
                    $dryRun,
                    $missingBefore,
                    $lateBefore,
                    &$emitted,
                    &$skipped,
                ): void {
                    $pending = TransmissionMdn::query()
                        ->where('mdn_status', 'pending')
                        ->where('created_at', '<=', $missingBefore)
                        ->with('document')
                        ->orderBy('id')
                        ->limit(500)
                        ->get();

                    foreach ($pending as $mdn) {
                        $document = $mdn->document;

                        if ($document === null) {
                            $skipped++;

                            continue;
                        }

                        $isLate = $mdn->created_at !== null && $mdn->created_at->lessThanOrEqualTo($lateBefore);
                        $code = $isLate ? 'LATE_MDN' : 'MISSING_MDN';

                        $this->line(sprintf(
                            '%s%s: mdn #%d document #%d → %s',
                            $dryRun ? '[dry-run] ' : '',
                            $tenant->name,
                            $mdn->getKey(),
                            $document->getKey(),
                            $code,
                        ));

                        if ($dryRun) {
                            $emitted++;

                            continue;
                        }

                        if ($isLate) {
                            $catalogSignal->lateMdn(
                                $document,
                                sprintf(
                                    'No Message Delivery Notification received within %d hours.',
                                    (int) config('tracepharma.as2_mdn.late_after_hours', 72),
                                ),
                            );
                        } else {
                            $catalogSignal->missingMdn(
                                $document,
                                sprintf(
                                    'No Message Delivery Notification received within %d hours.',
                                    (int) config('tracepharma.as2_mdn.missing_after_hours', 24),
                                ),
                            );
                        }

                        $emitted++;
                    }
                });
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$tenant->name}: {$exception->getMessage()}");
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        });

        $this->info(sprintf(
            'Pending MDN catalog signals complete. emitted=%d skipped=%d failed=%d%s',
            $emitted,
            $skipped,
            $failed,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }
}
