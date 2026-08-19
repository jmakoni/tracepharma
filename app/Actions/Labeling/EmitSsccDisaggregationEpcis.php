<?php

declare(strict_types=1);

namespace App\Actions\Labeling;

use App\Actions\Outbound\GenerateSsccDisaggregationDocument;
use App\Enums\EpcisAuthoredKind;
use App\Models\SsccLabelBatch;
use Illuminate\Support\Str;

final class EmitSsccDisaggregationEpcis
{
    public function __construct(
        private readonly GenerateSsccDisaggregationDocument $documentGenerator,
        private readonly PersistAuthoredSsccEpcis $persist,
    ) {}

    /**
     * @param  array{sync?: bool, dispatch?: bool, site_id?: int|null, event_time?: \Carbon\CarbonInterface|string}  $options
     */
    public function execute(SsccLabelBatch $batch, array $options = []): string
    {
        if ($batch->commissioned_at === null) {
            throw new \InvalidArgumentException('SSCC batch must be commissioned before emitting disaggregation EPCIS.');
        }

        $batch->loadMissing(['labels.children']);

        $siteId = array_key_exists('site_id', $options) && $options['site_id'] !== null && $options['site_id'] !== ''
            ? (int) $options['site_id']
            : null;
        $siteId ??= $batch->commission_site_id !== null ? (int) $batch->commission_site_id : null;
        $settings = array_key_exists('event_time', $options)
            ? ['event_time' => $options['event_time']]
            : null;
        $xml = $this->documentGenerator->forBatch($batch, siteId: $siteId, settings: $settings);
        $path = 'epcis/outbound/sscc-disaggregation-'.$batch->getKey().'-'.Str::uuid().'.xml';

        $sourceDocumentId = $batch->source_epcis_document_id;

        $this->persist->handle($xml, $path, [
            'trading_partner_id' => $batch->trading_partner_id,
            'original_filename' => "sscc-disaggregation-{$batch->getKey()}.xml",
            'authored_kind' => EpcisAuthoredKind::SsccDisaggregation,
            'notes' => 'Generated SSCC disaggregation EPCIS for sscc_label_batch_id='.$batch->getKey()
                .($sourceDocumentId !== null ? " source_epcis_document_id={$sourceDocumentId}" : '')
                .($batch->source_parent_sscc_urn !== null ? ' source_parent_sscc_urn='.$batch->source_parent_sscc_urn : '')
                .'.',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? false),
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        $batch->update([
            'disaggregation_file_path' => $path,
            'disaggregation_emitted_at' => now(),
        ]);

        return $path;
    }
}
