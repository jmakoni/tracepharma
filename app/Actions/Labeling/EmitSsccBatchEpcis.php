<?php

declare(strict_types=1);

namespace App\Actions\Labeling;

use App\Actions\Outbound\GenerateSsccAggregationDocument;
use App\Enums\EpcisAuthoredKind;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use Illuminate\Support\Str;

final class EmitSsccBatchEpcis
{
    public function __construct(
        private readonly GenerateSsccAggregationDocument $documentGenerator,
        private readonly PersistAuthoredSsccEpcis $persist,
    ) {}

    /**
     * @param  array{sync?: bool, dispatch?: bool, site_id?: int|null, event_time?: \Carbon\CarbonInterface|string}  $options
     */
    public function execute(SsccLabelBatch $batch, array $options = []): string
    {
        if ($batch->commissioned_at === null) {
            throw new \InvalidArgumentException('SSCC batch must be commissioned before emitting aggregation EPCIS.');
        }

        $siteId = array_key_exists('site_id', $options) && $options['site_id'] !== null && $options['site_id'] !== ''
            ? (int) $options['site_id']
            : null;
        $siteId ??= $batch->commission_site_id !== null ? (int) $batch->commission_site_id : null;
        $settings = array_key_exists('event_time', $options)
            ? ['event_time' => $options['event_time']]
            : null;
        $xml = $this->documentGenerator->forBatch($batch, siteId: $siteId, settings: $settings);
        $path = 'epcis/outbound/sscc-batch-'.$batch->getKey().'-'.Str::uuid().'.xml';

        $this->persist->handle($xml, $path, [
            'trading_partner_id' => $batch->trading_partner_id,
            'original_filename' => "sscc-batch-{$batch->getKey()}.xml",
            'authored_kind' => EpcisAuthoredKind::SsccAggregation,
            'notes' => 'Generated SSCC aggregation EPCIS for sscc_label_batch_id='.$batch->getKey().'.',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? false),
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        $now = now();

        $batch->update([
            'epcis_file_path' => $path,
            'epcis_emitted_at' => $now,
        ]);

        $batch->labels()->update([
            'epcis_file_path' => $path,
            'epcis_emitted_at' => $now,
        ]);

        return $path;
    }

    /**
     * @param  array{sync?: bool, dispatch?: bool, site_id?: int|null, event_time?: \Carbon\CarbonInterface|string}  $options
     */
    public function forLabel(SsccLabel $label, array $options = []): string
    {
        $label->loadMissing(['batch', 'children']);
        $siteId = isset($options['site_id']) ? (int) $options['site_id'] : null;
        $settings = array_key_exists('event_time', $options)
            ? ['event_time' => $options['event_time']]
            : null;
        $xml = $this->documentGenerator->forLabel($label, siteId: $siteId, settings: $settings);
        $path = 'epcis/outbound/sscc-label-'.$label->getKey().'-'.Str::uuid().'.xml';

        $this->persist->handle($xml, $path, [
            'trading_partner_id' => $label->batch?->trading_partner_id,
            'original_filename' => "sscc-label-{$label->getKey()}.xml",
            'authored_kind' => EpcisAuthoredKind::SsccAggregation,
            'notes' => 'Generated SSCC aggregation EPCIS for sscc_label_id='.$label->getKey()
                .($label->batch_id !== null ? ' sscc_label_batch_id='.$label->batch_id : '').'.',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? false),
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        $label->update([
            'epcis_file_path' => $path,
            'epcis_emitted_at' => now(),
        ]);

        return $path;
    }

    /**
     * Author a packing ADD for newly attached children only.
     *
     * @param  list<string>  $childEpcs
     * @param  array{sync?: bool, dispatch?: bool, site_id?: int|null, event_time?: \Carbon\CarbonInterface|string}  $options
     */
    public function forNewChildren(SsccLabel $label, array $childEpcs, array $options = []): string
    {
        $childEpcs = array_values(array_filter(array_map(
            static fn (mixed $urn): string => trim((string) $urn),
            $childEpcs,
        )));

        if ($childEpcs === []) {
            throw new \InvalidArgumentException('At least one new child EPC is required for incremental packing.');
        }

        $label->loadMissing('batch');
        $siteId = isset($options['site_id']) ? (int) $options['site_id'] : null;
        $settings = array_key_exists('event_time', $options)
            ? ['event_time' => $options['event_time']]
            : null;
        $xml = $this->documentGenerator->forLabelChildren($label, $childEpcs, siteId: $siteId, settings: $settings);
        $path = 'epcis/outbound/sscc-label-'.$label->getKey().'-add-'.Str::uuid().'.xml';

        $this->persist->handle($xml, $path, [
            'trading_partner_id' => $label->batch?->trading_partner_id,
            'original_filename' => "sscc-label-{$label->getKey()}-add.xml",
            'authored_kind' => EpcisAuthoredKind::SsccAggregation,
            'notes' => 'Generated SSCC aggregation EPCIS for sscc_label_id='.$label->getKey()
                .($label->batch_id !== null ? ' sscc_label_batch_id='.$label->batch_id : '')
                .'. incremental_add='.count($childEpcs).'.',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? false),
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        $now = now();

        $label->update([
            'epcis_file_path' => $path,
            'epcis_emitted_at' => $now,
        ]);

        $batch = $label->batch;
        if ($batch !== null) {
            $batch->update([
                'epcis_file_path' => $path,
                'epcis_emitted_at' => $now,
                'emit_epcis' => true,
            ]);
        }

        return $path;
    }
}
