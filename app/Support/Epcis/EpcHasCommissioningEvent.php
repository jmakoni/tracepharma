<?php

declare(strict_types=1);

namespace App\Support\Epcis;

use Illuminate\Support\Facades\DB;

/**
 * Whether EPCs already carry a commissioning ObjectEvent (any document).
 */
final class EpcHasCommissioningEvent
{
    /**
     * @param  list<int>  $epcIds
     * @return list<int> EPC ids that already have a commissioning ObjectEvent
     */
    public function among(array $epcIds): array
    {
        $epcIds = array_values(array_unique(array_filter(
            array_map(intval(...), $epcIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($epcIds === []) {
            return [];
        }

        return DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->whereIn('ee.epc_id', $epcIds)
            ->where('ev.event_type', 'ObjectEvent')
            ->where('ev.action', 'ADD')
            ->where(function ($query): void {
                $query->where('ev.biz_step', 'urn:epcglobal:cbv:bizstep:commissioning')
                    ->orWhere('ev.biz_step', 'commissioning')
                    ->orWhere('ev.biz_step', 'like', '%:commissioning');
            })
            ->distinct()
            ->pluck('ee.epc_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function for(int $epcId): bool
    {
        return $this->among([$epcId]) !== [];
    }
}
