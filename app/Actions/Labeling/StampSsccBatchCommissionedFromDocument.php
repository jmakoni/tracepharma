<?php

declare(strict_types=1);

namespace App\Actions\Labeling;

use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\EpcisDocument;
use App\Models\SsccLabel;

/**
 * Stamp SSCC label/batch commissioned_at only after an authored commissioning
 * document reaches validated status.
 */
final class StampSsccBatchCommissionedFromDocument
{
    public function handle(EpcisDocument $document): void
    {
        if ($document->status !== 'validated') {
            return;
        }

        $kind = $document->authored_kind;
        if ($kind !== EpcisAuthoredKind::SsccCommissioning) {
            return;
        }

        $batch = $document->ssccLabelBatch();
        if ($batch === null) {
            return;
        }

        $path = filled($batch->commissioning_epcis_file_path)
            ? (string) $batch->commissioning_epcis_file_path
            : (string) $document->payload_path;

        $now = now();

        SsccLabel::query()
            ->where('batch_id', $batch->getKey())
            ->whereNull('commissioned_at')
            ->update([
                'commissioning_epcis_file_path' => $path !== '' ? $path : null,
                'commissioned_at' => $now,
            ]);

        if ($batch->commissioned_at === null) {
            $batch->update([
                'commissioning_epcis_file_path' => $path !== '' ? $path : $batch->commissioning_epcis_file_path,
                'commissioned_at' => $now,
            ]);
        }
    }
}
