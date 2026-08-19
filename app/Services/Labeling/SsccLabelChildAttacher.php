<?php

namespace App\Services\Labeling;

use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccLabelChild;

class SsccLabelChildAttacher
{
    public function attachToLabel(SsccLabel $label, string $multilineEpcs): void
    {
        foreach ($this->parseLines($multilineEpcs) as $childEpc) {
            SsccLabelChild::query()->firstOrCreate(
                [
                    'sscc_label_id' => $label->id,
                    'child_epc' => $childEpc,
                ],
            );
        }
    }

    public function attachToBatch(SsccLabelBatch $batch, string $multilineEpcs): void
    {
        $batch->loadMissing('labels');

        foreach ($batch->labels as $label) {
            $this->attachToLabel($label, $multilineEpcs);
        }
    }

    /**
     * @throws \InvalidArgumentException If aggregation EPCIS has already been emitted for
     *                                   this label and the submitted list would remove a
     *                                   child, orphaning the open aggregation link the
     *                                   emitted EPCIS already claims.
     */
    public function syncLabel(SsccLabel $label, string $multilineEpcs): void
    {
        $epcs = $this->parseLines($multilineEpcs);

        if ($label->epcis_emitted_at !== null) {
            $removed = $label->children()
                ->whereNotIn('child_epc', $epcs)
                ->exists();

            if ($removed) {
                throw new \InvalidArgumentException(
                    'This label already has aggregation EPCIS emitted — emit disaggregation first before removing child EPCs.',
                );
            }
        }

        $label->children()
            ->whereNotIn('child_epc', $epcs)
            ->delete();

        $this->attachToLabel($label, $multilineEpcs);
    }

    /**
     * @return list<string>
     */
    private function parseLines(string $multilineEpcs): array
    {
        $lines = preg_split('/\R/', $multilineEpcs) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $lines))));
    }
}
