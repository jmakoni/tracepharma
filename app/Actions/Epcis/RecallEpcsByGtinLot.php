<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\Epc;
use Illuminate\Support\Collection;

/**
 * Find EPCs matching a GTIN-14 + lot for recall / investigation workflows.
 *
 * Thin BC wrapper around {@see SearchEpcisSchema}.
 */
final class RecallEpcsByGtinLot
{
    public function __construct(
        private readonly SearchEpcisSchema $search = new SearchEpcisSchema,
    ) {}

    /**
     * @return Collection<int, Epc>
     */
    public function handle(string $gtin14, string $lotNumber, int $limit = 1000): Collection
    {
        $result = $this->search->handle('epcs', [
            ['field' => 'epc.gtin14', 'operator' => 'eq', 'value' => $gtin14],
            ['field' => 'ilmd.lot_number', 'operator' => 'eq', 'value' => $lotNumber],
        ], $limit, $limit);

        /** @var Collection<int, Epc> $rows */
        $rows = $result['rows'];

        return $rows;
    }
}
