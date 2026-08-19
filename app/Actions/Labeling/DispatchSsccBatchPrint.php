<?php

namespace App\Actions\Labeling;

use App\Enums\ClientPrintBridge;
use App\Enums\LabelPrinterProtocol;
use App\Enums\SsccLabelPrintStatus;
use App\Enums\SsccPrintDeliveryMode;
use App\Enums\SsccPrintJobStatus;
use App\Jobs\PrintSsccLabelJob;
use App\Models\LabelPrinter;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccPrintJob;
use App\Models\Tenant;
use App\Services\Labeling\ZplLabelRenderer;
use App\Support\Labeling\ResolveClientPrintBridge;
use Illuminate\Support\Facades\Cache;

class DispatchSsccBatchPrint
{
    /**
     * @return array{
     *     mode: 'network'|'client',
     *     bridge: string,
     *     jobs: list<array{print_job_id: int, label_id: int, sscc_18: string, zpl: string, copies: int, printer_name: string}>
     * }
     */
    public function execute(SsccLabelBatch $batch, ?ClientPrintBridge $bridgeOverride = null): array
    {
        if ($batch->commissioned_at === null) {
            throw new \InvalidArgumentException(
                'This SSCC batch has not been commissioned yet. Commission it before printing labels.',
            );
        }

        if ($batch->label_printer_id === null || ! $batch->send_to_printer) {
            return ['mode' => 'network', 'bridge' => ClientPrintBridge::NetworkTcp->value, 'jobs' => []];
        }

        $printer = LabelPrinter::query()->find($batch->label_printer_id);
        $bridge = $this->resolveBridgeForPrinter($printer, $bridgeOverride);

        $tenant = $this->currentTenant();
        $batch->loadMissing('labels');
        $payloadJobs = [];

        foreach ($batch->labels as $label) {
            $job = $this->withLabelPrintLock((int) $label->id, function () use ($batch, $label, $bridge): SsccPrintJob {
                $this->supersedeOpenJobsForLabel((int) $label->id);

                $job = SsccPrintJob::query()->create([
                    'sscc_label_batch_id' => $batch->id,
                    'sscc_label_id' => $label->id,
                    'label_printer_id' => $batch->label_printer_id,
                    'copies' => $batch->copies_per_label,
                    'status' => SsccPrintJobStatus::Queued,
                    'delivery_mode' => $bridge->isClientSide()
                        ? SsccPrintDeliveryMode::Client
                        : SsccPrintDeliveryMode::Queue,
                    'queued_at' => now(),
                ]);

                $label->update([
                    'label_printer_id' => $batch->label_printer_id,
                    'print_status' => SsccLabelPrintStatus::Queued,
                ]);

                return $job;
            });

            if ($bridge->isClientSide()) {
                $payloadJobs[] = $this->clientJobPayload($job, $label, $printer, (int) $batch->copies_per_label);
            } else {
                PrintSsccLabelJob::dispatch($tenant, $job->id);
            }
        }

        return [
            'mode' => $bridge->isClientSide() ? 'client' : 'network',
            'bridge' => $bridge->value,
            'jobs' => $payloadJobs,
        ];
    }

    /**
     * @return array{
     *     mode: 'network'|'client',
     *     bridge: string,
     *     jobs: list<array{print_job_id: int, label_id: int, sscc_18: string, zpl: string, copies: int, printer_name: string}>,
     *     print_job: SsccPrintJob
     * }
     */
    public function forLabel(
        SsccLabel $label,
        int $printerId,
        int $copies = 1,
        ?ClientPrintBridge $bridgeOverride = null,
    ): array {
        if ($label->commissioned_at === null) {
            throw new \InvalidArgumentException(
                'This SSCC label has not been commissioned yet. Commission the batch before printing.',
            );
        }

        $printer = LabelPrinter::query()->findOrFail($printerId);
        $bridge = $this->resolveBridgeForPrinter($printer, $bridgeOverride);
        $tenant = $this->currentTenant();
        $copies = max(1, $copies);

        $job = $this->withLabelPrintLock((int) $label->id, function () use ($label, $printerId, $copies, $bridge): SsccPrintJob {
            $this->supersedeOpenJobsForLabel((int) $label->id);

            $job = SsccPrintJob::query()->create([
                'sscc_label_batch_id' => $label->batch_id,
                'sscc_label_id' => $label->id,
                'label_printer_id' => $printerId,
                'copies' => $copies,
                'status' => SsccPrintJobStatus::Queued,
                'delivery_mode' => $bridge->isClientSide()
                    ? SsccPrintDeliveryMode::Client
                    : SsccPrintDeliveryMode::Queue,
                'queued_at' => now(),
            ]);

            $label->update([
                'label_printer_id' => $printerId,
                'print_status' => SsccLabelPrintStatus::Queued,
            ]);

            return $job;
        });

        $payloadJobs = [];

        if ($bridge->isClientSide()) {
            $payloadJobs[] = $this->clientJobPayload($job, $label, $printer, $copies);
        } else {
            PrintSsccLabelJob::dispatch($tenant, $job->id);
        }

        return [
            'mode' => $bridge->isClientSide() ? 'client' : 'network',
            'bridge' => $bridge->value,
            'jobs' => $payloadJobs,
            'print_job' => $job,
        ];
    }

    public function resolveBridgeForPrinter(?LabelPrinter $printer, ?ClientPrintBridge $override = null): ClientPrintBridge
    {
        if ($printer === null) {
            return $override ?? app(ResolveClientPrintBridge::class)->handle();
        }

        $fromPrinter = match ($printer->protocolOrDefault()) {
            LabelPrinterProtocol::QzTray => ClientPrintBridge::QzTray,
            LabelPrinterProtocol::ZebraBrowserPrint => ClientPrintBridge::ZebraBrowserPrint,
            LabelPrinterProtocol::ZplRaw => ClientPrintBridge::NetworkTcp,
        };

        if ($fromPrinter->isClientSide()) {
            return $fromPrinter;
        }

        $bridge = $override ?? app(ResolveClientPrintBridge::class)->handle();

        if ($bridge->isClientSide()) {
            $this->assertWorkstationPrinterNameConfigured($printer);
        }

        return $bridge;
    }

    /**
     * Serialize supersede+create per label so concurrent dispatches (e.g. batch print racing
     * a reprint/retry for the same label) cannot both leave open jobs queued for one label.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withLabelPrintLock(int $labelId, \Closure $callback)
    {
        return Cache::lock('sscc-print-label:'.$labelId, 10)->block(5, $callback);
    }

    private function supersedeOpenJobsForLabel(int $labelId): void
    {
        SsccPrintJob::query()
            ->where('sscc_label_id', $labelId)
            ->whereIn('status', [
                SsccPrintJobStatus::Queued->value,
                SsccPrintJobStatus::Printing->value,
            ])
            ->update([
                'status' => SsccPrintJobStatus::Failed,
                'last_error' => 'Superseded by a newer print request.',
                'client_print_token' => null,
            ]);
    }

    private function assertWorkstationPrinterNameConfigured(LabelPrinter $printer): void
    {
        $clientPrinterName = data_get($printer->settings ?? [], 'client_printer_name');

        if (! is_string($clientPrinterName) || trim($clientPrinterName) === '') {
            throw new \InvalidArgumentException(
                'Set the workstation printer name on the Label Printer before using client-side printing.',
            );
        }
    }

    /**
     * @return array{print_job_id: int, label_id: int, sscc_18: string, zpl: string, copies: int, printer_name: string}
     */
    private function clientJobPayload(
        SsccPrintJob $job,
        SsccLabel $label,
        ?LabelPrinter $printer,
        int $copies,
    ): array {
        $zpl = app(ZplLabelRenderer::class)->render([
            'sscc_18' => (string) $label->sscc_18,
            'hrt' => (string) $label->hrt,
            'ship_to_name' => $label->ship_to_name,
            'ship_from_name' => tenant()?->name,
            'copies' => $copies,
        ]);

        return [
            'print_job_id' => (int) $job->id,
            'label_id' => (int) $label->id,
            'sscc_18' => (string) $label->sscc_18,
            'zpl' => $zpl,
            'copies' => $copies,
            'printer_name' => $printer?->clientPrinterName() ?? 'default',
        ];
    }

    private function currentTenant(): Tenant
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('SSCC print dispatch requires an initialized tenant context.');
        }

        return $tenant;
    }
}
