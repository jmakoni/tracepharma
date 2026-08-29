<?php

namespace App\Support\Disposition;

use App\Support\Shipping\ShippableEpcsAtSite;
use Carbon\CarbonInterface;

/**
 * On-hand EPCs that were commissioned on or before a cutoff and never shipped.
 */
final class FindNeverShippedCommissionedEpcs
{
    public function __construct(
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
    ) {}

    /**
     * @return list<int>
     */
    public function atSite(int $siteId, CarbonInterface $commissionedBefore): array
    {
        if ($siteId <= 0) {
            return [];
        }

        return $this->shippableEpcsAtSite
            ->query($siteId)
            ->whereExists(function ($exists) use ($commissionedBefore): void {
                $exists->selectRaw('1')
                    ->from('event_epcs as ee_c')
                    ->join('epcis_events as ev_c', 'ev_c.id', '=', 'ee_c.event_id')
                    ->whereColumn('ee_c.epc_id', 'epcs.id')
                    ->where('ev_c.event_type', 'ObjectEvent')
                    ->where('ev_c.action', 'ADD')
                    ->where(function ($query): void {
                        $query->where('ev_c.biz_step', 'urn:epcglobal:cbv:bizstep:commissioning')
                            ->orWhere('ev_c.biz_step', 'commissioning')
                            ->orWhere('ev_c.biz_step', 'like', '%:commissioning');
                    })
                    ->where('ev_c.event_time', '<=', $commissionedBefore);
            })
            ->whereNotExists(function ($exists): void {
                $exists->selectRaw('1')
                    ->from('event_epcs as ee_s')
                    ->join('epcis_events as ev_s', 'ev_s.id', '=', 'ee_s.event_id')
                    ->whereColumn('ee_s.epc_id', 'epcs.id')
                    ->where('ev_s.event_type', 'ObjectEvent')
                    ->where('ev_s.biz_step', 'like', '%shipping%');
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
