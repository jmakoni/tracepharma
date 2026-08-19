<?php

namespace App\Support\Labeling;

use App\Enums\SsccLabelPrintStatus;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;

class SsccBatchPrintCompletion
{
    public function refreshBatchPrintedAt(?int $batchId): void
    {
        if ($batchId === null) {
            return;
        }

        $batch = SsccLabelBatch::query()->find($batchId);

        if ($batch === null) {
            return;
        }

        $pendingLabels = SsccLabel::query()
            ->where('batch_id', $batchId)
            ->where('print_status', '!=', SsccLabelPrintStatus::Printed->value)
            ->exists();

        if ($pendingLabels) {
            return;
        }

        $maxPrintedAt = SsccLabel::query()
            ->where('batch_id', $batchId)
            ->max('printed_at');

        $batch->update([
            'printed_at' => $maxPrintedAt ?? now(),
        ]);
    }
}
