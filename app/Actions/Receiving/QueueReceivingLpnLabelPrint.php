<?php

declare(strict_types=1);

namespace App\Actions\Receiving;

use App\Actions\Labeling\DispatchSsccBatchPrint;
use App\Actions\Labeling\EmitSsccBatchCommissioningEpcis;
use App\Actions\Labeling\GenerateSsccLabelBatch;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Models\Epcis\EpcisDocument;
use App\Models\LabelPrinter;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use App\Support\TenantSsccSettings;
use Illuminate\Support\Facades\Cache;

class QueueReceivingLpnLabelPrint
{
    /**
     * Generates a single LPN label and queues print when a default printer is configured.
     *
     * Receiving auto-print requires a network TCP (ZPL) printer. Client-side bridges
     * (QZ Tray / Zebra Browser Print) must be printed from the SSCC Labels batch page.
     */
    public function __construct(
        private readonly GenerateSsccLabelBatch $generateSsccLabelBatch,
        private readonly DispatchSsccBatchPrint $dispatchSsccBatchPrint,
        private readonly EmitSsccBatchCommissioningEpcis $commissioningEmitter,
    ) {}

    public function execute(int $epcisDocumentId): SsccLabel
    {
        $document = EpcisDocument::query()->findOrFail($epcisDocumentId);

        if (! in_array($document->status, ['parsed', 'validated'], true)) {
            throw new \InvalidArgumentException('Shipment processing must finish before printing an LPN label.');
        }

        // Concurrent requests for the same shipment must not both pass the idempotency
        // check and generate duplicate LPN batches — serialize per source document.
        return Cache::lock('receiving-lpn:'.$document->getKey(), 30)
            ->block(10, fn (): SsccLabel => $this->generateOrReuse($document));
    }

    private function generateOrReuse(EpcisDocument $document): SsccLabel
    {
        $existingBatch = SsccLabelBatch::query()
            ->where('source_epcis_document_id', $document->getKey())
            ->where('notes', 'like', 'Receiving LPN%')
            ->where('status', SsccLabelBatchStatus::Completed)
            ->whereHas('labels')
            ->latest('id')
            ->first();

        if ($existingBatch !== null) {
            /** @var SsccLabel $existingLabel */
            $existingLabel = $existingBatch->labels()->latest('id')->firstOrFail();

            if ($existingLabel->commissioned_at !== null) {
                return $existingLabel;
            }

            $siteId = (int) ($existingBatch->commission_site_id
                ?? TenantSettings::forTenant(tenant())->defaultShipFromSiteId()
                ?? TenantSettings::forTenant(tenant())->defaultReceiveSiteId()
                ?? 0);

            if ($siteId <= 0) {
                throw new \InvalidArgumentException(
                    'Configure a default ship-from or receive site in Organization settings before printing LPN labels.',
                );
            }

            $this->commissioningEmitter->execute($existingBatch->fresh(['labels']), [
                'site_id' => $siteId,
                'sync' => true,
            ]);

            // Mirror the generate path: a re-commission that succeeds must still reach the
            // printer when the batch was configured to send to one.
            $recommissioned = $existingBatch->fresh(['labels']);
            if ($recommissioned->send_to_printer && $recommissioned->label_printer_id !== null) {
                $this->dispatchSsccBatchPrint->execute($recommissioned);
            }

            return $existingLabel->fresh() ?? $existingLabel;
        }

        $generatingBatch = SsccLabelBatch::query()
            ->where('source_epcis_document_id', $document->getKey())
            ->where('notes', 'like', 'Receiving LPN%')
            ->where('status', SsccLabelBatchStatus::Generating)
            ->latest('id')
            ->first();

        if ($generatingBatch !== null) {
            throw new \InvalidArgumentException(
                'An LPN label is already being generated for this shipment. Please try again in a moment.',
            );
        }

        $settings = TenantSsccSettings::resolve();

        if (blank($settings['company_prefix'])) {
            throw new \InvalidArgumentException('Configure a GS1 Company Prefix in Settings before printing LPN labels.');
        }

        $printer = LabelPrinter::query()
            ->where('enabled', true)
            ->where('is_default', true)
            ->first();

        if ($printer === null) {
            throw new \InvalidArgumentException('Configure a default label printer before printing LPN labels.');
        }

        $bridge = $this->dispatchSsccBatchPrint->resolveBridgeForPrinter($printer);

        if ($bridge->isClientSide()) {
            throw new \InvalidArgumentException(
                'Receiving auto-print requires a Network TCP label printer. Configure a ZPL network printer as default, or print LPN labels from the SSCC Labels batch page.',
            );
        }

        $tenant = tenant();
        $tenantSettings = TenantSettings::forTenant($tenant);
        $siteId = $tenantSettings->defaultShipFromSiteId()
            ?? $tenantSettings->defaultReceiveSiteId()
            ?? EligibleReceiveSites::forOrganization()->value('id');

        if ($siteId === null || (int) $siteId <= 0) {
            throw new \InvalidArgumentException(
                'Configure a default ship-from or receive site in Organization settings before printing LPN labels.',
            );
        }

        $shipmentRef = (string) ($document->customer_po
            ?? $document->asn_number
            ?? $document->original_filename
            ?? $document->getKey());

        $batch = $this->generateSsccLabelBatch->execute([
            'label_count' => 1,
            'copies_per_label' => 1,
            'send_to_printer' => true,
            'emit_epcis' => false,
            'emit_disaggregation' => false,
            'label_printer_id' => $printer->id,
            'source_epcis_document_id' => $document->getKey(),
            'site_id' => (int) $siteId,
            'allocation_mode' => SsccAllocationMode::Sequential->value,
            'ship_to_name' => $document->ship_to_name ?? $tenant?->name,
            'ship_to_gln' => $document->ship_to_gln ?? $tenantSettings->gln(),
            'notes' => sprintf('Receiving LPN for inbound shipment %s', $shipmentRef),
        ]);

        return $batch->labels()->firstOrFail();
    }
}
