<?php

namespace App\Jobs;

use App\Enums\LabelPrinterProtocol;
use App\Enums\SsccLabelPrintStatus;
use App\Enums\SsccPrintJobStatus;
use App\Models\SsccPrintJob;
use App\Models\Tenant;
use App\Services\Labeling\NetworkPrinterClient;
use App\Services\Labeling\SsccSerialPoolService;
use App\Services\Labeling\ZplLabelRenderer;
use App\Support\Labeling\SsccBatchPrintCompletion;
use App\Support\Labeling\SsccPrintJobLabelGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PrintSsccLabelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Tenant $tenant,
        public readonly int $printJobId,
    ) {}

    public function handle(
        ZplLabelRenderer $renderer,
        NetworkPrinterClient $printerClient,
        SsccSerialPoolService $poolService,
        SsccBatchPrintCompletion $batchPrintCompletion,
    ): void {
        $this->tenant->run(function () use ($renderer, $printerClient, $poolService, $batchPrintCompletion): void {
            $job = SsccPrintJob::query()
                ->with(['label', 'printer', 'batch'])
                ->findOrFail($this->printJobId);

            if ($job->status === SsccPrintJobStatus::Printed) {
                return;
            }

            if (SsccPrintJobLabelGuard::isSupersededJob($job)) {
                return;
            }

            if (SsccPrintJobLabelGuard::hasNewerJobForLabel($job)) {
                return;
            }

            $printer = $job->printer;

            if ($printer === null || ! $printer->enabled) {
                $this->failJob($job, 'Label printer is not available.');

                return;
            }

            $protocol = $printer->protocolOrDefault();

            if ($protocol->isClientSide()) {
                $this->failJob(
                    $job,
                    sprintf(
                        'Printer "%s" uses %s. Client-side bridges must print from the browser workstation — server queue cannot send TCP to this printer.',
                        $printer->name,
                        $protocol->label(),
                    ),
                );

                return;
            }

            if ($protocol === LabelPrinterProtocol::ZplRaw && (blank($printer->ip_address) || $printer->port === null)) {
                $this->failJob($job, 'Label printer network address (IP and port) is not configured.');

                return;
            }

            $job->update([
                'status' => SsccPrintJobStatus::Printing,
                'attempts' => $job->attempts + 1,
            ]);

            try {
                $label = $job->label;
                $zpl = $renderer->render([
                    'sscc_18' => $label->sscc_18,
                    'hrt' => $label->hrt,
                    'ship_to_name' => $label->ship_to_name,
                    'ship_from_name' => $this->tenant->name,
                    'copies' => $job->copies,
                ]);

                $printerClient->send($printer->ip_address, $printer->port, $zpl);

                $job->refresh();

                if (
                    SsccPrintJobLabelGuard::isSupersededJob($job)
                    || SsccPrintJobLabelGuard::hasNewerJobForLabel($job)
                    || $job->status === SsccPrintJobStatus::Failed
                ) {
                    // Another print request superseded this job while TCP was in flight.
                    // Physical print may have occurred; do not overwrite newer label state.
                    return;
                }

                $printedAt = now();

                $job->update([
                    'status' => SsccPrintJobStatus::Printed,
                    'printed_at' => $printedAt,
                    'last_error' => null,
                ]);

                $label->update([
                    'print_status' => SsccLabelPrintStatus::Printed,
                    'printed_copies' => $job->copies,
                    'printed_at' => $printedAt,
                ]);

                $pool = $poolService->lockOrCreate(
                    $label->company_prefix,
                    (int) $label->extension_digit,
                );

                $poolService->recordPrinted($pool, $label->serial_reference_int, $printedAt);

                $batchPrintCompletion->refreshBatchPrintedAt($job->sscc_label_batch_id);
            } catch (\Throwable $exception) {
                $this->failJob($job, $exception->getMessage());

                throw $exception;
            }
        });
    }

    private function failJob(SsccPrintJob $job, string $message): void
    {
        $job->update([
            'status' => SsccPrintJobStatus::Failed,
            'last_error' => $message,
        ]);

        if (! SsccPrintJobLabelGuard::shouldSkipFailureOnLabel($job)) {
            $job->label?->update([
                'print_status' => SsccLabelPrintStatus::Failed,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['tenant:'.$this->tenant->getTenantKey(), 'sscc-print'];
    }
}
