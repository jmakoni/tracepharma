<?php

namespace App\Actions\Labeling;

use App\Models\SsccLabel;

class GenerateSsccLabel
{
    public function __construct(
        private readonly GenerateSsccLabelBatch $batchGenerator,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(array $input): SsccLabel
    {
        $input['label_count'] = 1;

        if (! isset($input['allocation_mode'])) {
            $input['allocation_mode'] = ($input['auto_allocate_serial'] ?? true)
                ? 'sequential'
                : 'range';
            $input['range_start'] = $input['serial_reference'] ?? null;
            $input['range_end'] = $input['serial_reference'] ?? null;
        }

        $batch = $this->batchGenerator->execute($input);

        return $batch->labels()->firstOrFail();
    }
}
