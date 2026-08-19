<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Receiving\QueueReceivingLpnLabelPrint;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateReceivingLpnLabelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $epcisDocumentId,
        public readonly string $tenantId,
    ) {}

    public function handle(QueueReceivingLpnLabelPrint $queueReceivingLpnLabelPrint): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $tenant->run(function () use ($queueReceivingLpnLabelPrint): void {
            $label = $queueReceivingLpnLabelPrint->execute($this->epcisDocumentId);

            Log::info('Receiving LPN label queued for print.', [
                'tenant_id' => $this->tenantId,
                'epcis_document_id' => $this->epcisDocumentId,
                'sscc_label_id' => $label->id,
                'sscc_18' => $label->sscc_18,
            ]);
        });
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['tenant:'.$this->tenantId, 'receiving-lpn-print'];
    }
}
