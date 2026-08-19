<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SsccLabelPrintStatus;
use App\Enums\SsccPrintDeliveryMode;
use App\Enums\SsccPrintJobStatus;
use App\Models\SsccPrintJob;
use App\Models\Tenant;
use Illuminate\Console\Command;

class FailStaleClientPrintJobs extends Command
{
    protected $signature = 'sscc:fail-stale-client-print-jobs {--tenant=}';

    protected $description = 'Fail stale client SSCC print jobs (Queued >15m or abandoned Printing >45m) and clear ownership tokens';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $failedJobs = 0;
        $updatedLabels = 0;

        $tenants = $tenantId
            ? Tenant::query()->where('id', $tenantId)->get()
            : Tenant::query()->where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use (&$failedJobs, &$updatedLabels): void {
                $staleBefore = now()->subMinutes(15);
                $stalePrintingBefore = now()->subMinutes(45);

                $staleJobs = SsccPrintJob::query()
                    ->with(['label'])
                    ->where('delivery_mode', SsccPrintDeliveryMode::Client)
                    ->where(function ($query) use ($staleBefore, $stalePrintingBefore): void {
                        $query
                            ->where(function ($queued) use ($staleBefore): void {
                                $queued
                                    ->where('status', SsccPrintJobStatus::Queued)
                                    ->where('queued_at', '<', $staleBefore);
                            })
                            ->orWhere(function ($printing) use ($stalePrintingBefore): void {
                                // Abandoned after startJob but browser never completed.
                                $printing
                                    ->where('status', SsccPrintJobStatus::Printing)
                                    ->where('updated_at', '<', $stalePrintingBefore);
                            });
                    })
                    ->get();

                foreach ($staleJobs as $job) {
                    $job->update([
                        'status' => SsccPrintJobStatus::Failed,
                        'last_error' => 'Client print timed out — browser did not acknowledge.',
                        'client_print_token' => null,
                    ]);
                    $failedJobs++;

                    $label = $job->label;

                    if ($label !== null && $label->print_status === SsccLabelPrintStatus::Queued) {
                        $label->update([
                            'print_status' => SsccLabelPrintStatus::Failed,
                        ]);
                        $updatedLabels++;
                    }
                }
            });
        }

        $this->info("Failed {$failedJobs} stale client print job(s); updated {$updatedLabels} label(s).");

        return self::SUCCESS;
    }
}
