<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Labeling\ReconcileSsccBatchL3L4;
use App\Enums\SsccLabelBatchStatus;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\Tenant;
use App\Support\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Emit L2_L3_RECONCILIATION_FAILURE when SSCC batch label counts diverge from L4 commissioning events.
 */
class ReconcileSsccBatchL3L4Command extends Command
{
    protected $signature = 'sscc:reconcile-l3-l4
                            {--tenant= : Limit to a single tenant id}
                            {--site= : Limit to commission_site_id}
                            {--batch= : Limit to a single sscc_label_batches id}
                            {--sscc= : Limit to the batch owning this SSCC-18 / URN}
                            {--dry-run : Report mismatches without opening cases}';

    protected $description = 'Reconcile SSCC label batches against L4 commissioning ObjectEvents';

    public function handle(ReconcileSsccBatchL3L4 $reconcile): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $matched = 0;
        $mismatched = 0;
        $opened = 0;
        $skipped = 0;
        $failed = 0;

        $query = Tenant::query()->where('status', 'active')->orderBy('name');

        if (is_string($tenantId) && $tenantId !== '') {
            $query->where('id', $tenantId);
        }

        $query->cursor()->each(function (Tenant $tenant) use (
            $reconcile,
            $dryRun,
            &$matched,
            &$mismatched,
            &$opened,
            &$skipped,
            &$failed,
        ): void {
            if (! $tenant->features()->supportsSsccLabeling()) {
                return;
            }

            try {
                TenantRunner::run($tenant, function () use (
                    $tenant,
                    $reconcile,
                    $dryRun,
                    &$matched,
                    &$mismatched,
                    &$opened,
                    &$skipped,
                ): void {
                    foreach ($this->batchesForTenant() as $batch) {
                        $result = $reconcile->handle($batch, $dryRun);

                        if ($result['skipped']) {
                            $skipped++;

                            continue;
                        }

                        if ($result['matched']) {
                            $matched++;

                            continue;
                        }

                        $mismatched++;
                        if ($result['opened']) {
                            $opened++;
                        }

                        $this->line(sprintf(
                            '[%s] batch #%d expected=%d actual=%d case=%s%s',
                            $tenant->getTenantKey(),
                            (int) $batch->getKey(),
                            $result['expected'],
                            $result['actual'],
                            $result['exception_case_id'] !== null ? (string) $result['exception_case_id'] : 'none',
                            $dryRun ? ' (dry-run)' : '',
                        ));
                    }
                });
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf(
                    'Tenant %s failed: %s',
                    $tenant->getTenantKey(),
                    $e->getMessage(),
                ));
            }
        });

        $this->info(sprintf(
            'Reconcile complete: matched=%d mismatched=%d opened=%d skipped=%d failed=%d',
            $matched,
            $mismatched,
            $opened,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    /**
     * @return Collection<int, SsccLabelBatch>
     */
    private function batchesForTenant()
    {
        $query = SsccLabelBatch::query()
            ->where('status', SsccLabelBatchStatus::Completed)
            ->where(function ($q): void {
                $q->whereNotNull('commissioned_at')
                    ->orWhereNotNull('commissioning_epcis_file_path');
            })
            ->orderBy('id');

        $siteId = $this->option('site');
        if (is_string($siteId) && $siteId !== '' && ctype_digit($siteId)) {
            $query->where('commission_site_id', (int) $siteId);
        }

        $batchId = $this->option('batch');
        if (is_string($batchId) && $batchId !== '' && ctype_digit($batchId)) {
            $query->whereKey((int) $batchId);
        }

        $sscc = $this->option('sscc');
        if (is_string($sscc) && $sscc !== '') {
            $sscc = trim($sscc);
            $batchIds = SsccLabel::query()
                ->where(function ($q) use ($sscc): void {
                    $q->where('sscc_18', $sscc)
                        ->orWhere('sscc_urn', $sscc);
                })
                ->pluck('batch_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->all();

            if ($batchIds === []) {
                return collect();
            }

            $query->whereIn('id', $batchIds);
        }

        return $query->get();
    }
}
