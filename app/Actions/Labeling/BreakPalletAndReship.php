<?php

namespace App\Actions\Labeling;

use App\Enums\SsccReshipMode;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\SsccLabelBatch;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantFeatures;
use Illuminate\Support\Arr;

/**
 * Break an inbound pallet hierarchy into new outbound SSCC labels (reship / re-label).
 *
 * Expects {@see GenerateSsccLabelBatch} input plus:
 * - source_epcis_document_id
 * - source_parent_sscc_urn
 * - selected_child_epcs
 * - reship_mode (per_child | combined)
 */
class BreakPalletAndReship
{
    public function __construct(
        private readonly GenerateSsccLabelBatch $generateBatch,
        private readonly EpcCustodyGate $custodyGate,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input): SsccLabelBatch
    {
        $this->assertTenantMayUnpack();

        $documentId = (int) ($input['source_epcis_document_id'] ?? 0);
        $parentUrn = $this->normalizeEpc((string) ($input['source_parent_sscc_urn'] ?? ''));

        if ($documentId <= 0 || $parentUrn === '') {
            throw new \InvalidArgumentException('Select an inbound shipment and source pallet SSCC.');
        }

        $selectedChildren = array_values(array_unique(array_filter(array_map(
            fn ($child): string => $this->normalizeEpc((string) $child),
            (array) ($input['selected_child_epcs'] ?? []),
        ))));

        if ($selectedChildren === []) {
            throw new \InvalidArgumentException('Select at least one child EPC from the source pallet.');
        }

        $parentEpc = Epc::query()->where('epc_uri', $parentUrn)->first();
        if ($parentEpc === null) {
            throw new \InvalidArgumentException('Source parent SSCC not found.');
        }

        $this->custodyGate->assertOperableFor($parentEpc, 'break and pack');

        $childEpcIds = [];
        $childEpcs = [];

        foreach ($selectedChildren as $childUrn) {
            $childEpc = Epc::query()->where('epc_uri', $childUrn)->first();
            if ($childEpc === null) {
                throw new \InvalidArgumentException("Child EPC not found: {$childUrn}");
            }

            $hasOpenLink = AggregationLink::query()
                ->where('parent_epc_id', $parentEpc->getKey())
                ->where('child_epc_id', $childEpc->getKey())
                ->whereNull('valid_to')
                ->exists();

            if (! $hasOpenLink) {
                throw new \InvalidArgumentException(
                    "Child {$childUrn} is not an open link under the source parent.",
                );
            }

            $childEpcIds[] = (int) $childEpc->getKey();
            $childEpcs[] = $childEpc;
        }

        $this->custodyGate->assertOperableFor($childEpcs, 'break and pack');

        $reshipMode = SsccReshipMode::tryFrom((string) ($input['reship_mode'] ?? SsccReshipMode::PerChild->value))
            ?? SsccReshipMode::PerChild;

        $batchInput = Arr::except($input, [
            'source_epcis_document_id',
            'source_parent_sscc_urn',
            'selected_child_epcs',
            'reship_mode',
        ]);

        $batchInput['source_epcis_document_id'] = $documentId;
        $batchInput['source_parent_sscc_urn'] = $parentUrn;

        // Reship always breaks the source hierarchy. The DELETE (disaggregation) and the new ADD
        // (aggregation) must both be ingested before returning, otherwise the children keep an
        // open link to the source pallet and can be packed a second time.
        $batchInput['emit_disaggregation'] = true;
        $batchInput['emit_epcis'] = true;
        $batchInput['epcis_sync'] = true;

        if ($reshipMode === SsccReshipMode::PerChild) {
            $batchInput['label_count'] = count($selectedChildren);
            $batchInput['child_epcs_per_label'] = array_map(
                fn (string $child): array => [$child],
                $selectedChildren,
            );
        } else {
            $batchInput['label_count'] = 1;
            $batchInput['child_epcs'] = implode("\n", $selectedChildren);
        }

        $batch = $this->generateBatch->execute($batchInput);

        $this->recordStillOpenSourceLinks($batch, (int) $parentEpc->getKey(), $childEpcIds);

        return $batch;
    }

    /**
     * The disaggregation ingest closes the source links. If any survived, the hierarchy is stale —
     * record it on the batch so the caller warns instead of reporting a clean reship.
     *
     * @param  list<int>  $childEpcIds
     */
    private function recordStillOpenSourceLinks(SsccLabelBatch $batch, int $parentEpcId, array $childEpcIds): void
    {
        if ($childEpcIds === []) {
            return;
        }

        $stillOpen = AggregationLink::query()
            ->where('parent_epc_id', $parentEpcId)
            ->whereIn('child_epc_id', $childEpcIds)
            ->whereNull('valid_to')
            ->count();

        if ($stillOpen === 0) {
            return;
        }

        $message = "EPCIS disaggregation: {$stillOpen} child link(s) are still open under the source pallet — "
            .'re-emit the disaggregation document before shipping.';

        $existing = trim((string) ($batch->error_message ?? ''));
        $batch->update([
            'error_message' => $existing !== '' ? $existing."\n".$message : $message,
        ]);
        $batch->emitErrors = [...$batch->emitErrors, $message];
    }

    private function assertTenantMayUnpack(): void
    {
        $policy = ReceivingPolicy::forTenant(tenant());
        $features = TenantFeatures::forTenant(tenant());

        if (! $policy->canUnpackAtReceive() && ! $features->supportsUnpacking()) {
            throw new \InvalidArgumentException('This tenant profile cannot unpack hierarchy.');
        }
    }

    private function normalizeEpc(string $input): string
    {
        $input = trim($input);

        if ($input === '') {
            return '';
        }

        if (str_starts_with($input, 'urn:epc:id:sgtin:')) {
            return $input;
        }

        if (str_starts_with($input, 'urn:epc:id:sscc:')) {
            return $input;
        }

        if (preg_match('/^\d+\.\d+$/', $input) === 1) {
            return 'urn:epc:id:sscc:'.$input;
        }

        return $input;
    }
}
