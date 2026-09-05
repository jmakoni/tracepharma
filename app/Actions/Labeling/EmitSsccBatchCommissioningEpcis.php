<?php

declare(strict_types=1);

namespace App\Actions\Labeling;

use App\Actions\Outbound\GenerateSsccCommissioningDocument;
use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\EpcisDocument;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use Illuminate\Support\Str;

final class EmitSsccBatchCommissioningEpcis
{
    public function __construct(
        private readonly GenerateSsccCommissioningDocument $documentGenerator,
        private readonly PersistAuthoredSsccEpcis $persist,
        private readonly StampSsccBatchCommissionedFromDocument $stampCommissioned,
    ) {}

    /**
     * @param  array{sync?: bool, dispatch?: bool, site_id?: int|null}  $options
     */
    public function execute(SsccLabelBatch $batch, array $options = []): ?string
    {
        $batch->loadMissing('labels');

        $pending = $batch->labels->filter(
            fn (SsccLabel $label): bool => $label->commissioned_at === null
                && strlen(preg_replace('/\D/', '', (string) $label->sscc_18) ?? '') === 18
                && filled($label->sscc_urn),
        )->values();

        if ($pending->isEmpty()) {
            return $batch->commissioning_epcis_file_path;
        }

        $siteId = array_key_exists('site_id', $options) && $options['site_id'] !== null && $options['site_id'] !== ''
            ? (int) $options['site_id']
            : null;
        $siteId ??= $batch->commission_site_id !== null ? (int) $batch->commission_site_id : null;
        $xml = $this->documentGenerator->forBatch($batch, $pending, siteId: $siteId);
        $path = 'epcis/outbound/sscc-batch-'.$batch->getKey().'-commission-'.Str::uuid().'.xml';

        $sync = (bool) ($options['sync'] ?? false);

        $document = $this->persist->handle($xml, $path, [
            'trading_partner_id' => $batch->trading_partner_id,
            'original_filename' => "sscc-batch-{$batch->getKey()}-commission.xml",
            'authored_kind' => EpcisAuthoredKind::SsccCommissioning,
            'notes' => 'Generated SSCC commissioning EPCIS for sscc_label_batch_id='.$batch->getKey().'.',
            'ship_from_site_id' => $siteId,
            'sync' => $sync,
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        $pendingIds = $pending->pluck('id')->all();

        // Path is recorded immediately for matching/retry; commissioned_at waits for validated ingest.
        SsccLabel::query()
            ->whereIn('id', $pendingIds)
            ->update([
                'commissioning_epcis_file_path' => $path,
            ]);

        $batch->update([
            'commissioning_epcis_file_path' => $path,
        ]);

        // Sync ingest may have already stamped via ProcessEpcisDocument (notes fallback
        // before path was written). Re-run after path is persisted so labels match path.
        if ($sync && $document instanceof EpcisDocument) {
            $this->stampCommissioned->handle($document->refresh());
        }

        return $path;
    }
}
