<?php

namespace App\Support\Shipping;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Product × lot rollups over {@see ShippableEpcsAtSite} custody (not a WMS stock table).
 *
 * @phpstan-type LotRow array{
 *     gtin14: string,
 *     lot_number: string,
 *     total: int,
 *     pickable: int,
 *     hold_count: int,
 *     sgtin_count: int,
 *     sscc_count: int,
 *     min_expiry: ?string,
 *     max_expiry: ?string,
 *     has_quarantine: bool,
 *     has_near_expiry: bool
 * }
 */
final class OnHandLotRollup
{
    public const SSCC_PRODUCT_KEY = '__sscc__';

    public function __construct(
        private readonly ShippableEpcsAtSite $shippable,
    ) {}

    /**
     * @return Collection<int, LotRow>
     */
    public function rows(int $siteId, ?int $principalId = null, int $nearExpiryDays = 90): Collection
    {
        if ($siteId < 1) {
            return collect();
        }

        return collect($this->aggregate($siteId, $principalId, $nearExpiryDays));
    }

    /**
     * @return LengthAwarePaginator<int, LotRow>
     */
    public function paginate(
        int $siteId,
        ?int $principalId = null,
        int $nearExpiryDays = 90,
        int $perPage = 25,
        string $pageName = 'lotsPage',
    ): LengthAwarePaginator {
        $all = $this->rows($siteId, $principalId, $nearExpiryDays);
        $page = max(1, (int) request()->input($pageName, 1));
        $slice = $all->forPage($page, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $all->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'pageName' => $pageName,
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @return list<LotRow>
     */
    private function aggregate(int $siteId, ?int $principalId, int $nearExpiryDays): array
    {
        $base = $this->shippable->query($siteId);
        if ($principalId !== null && $principalId > 0) {
            $base->where('epcs.principal_id', $principalId);
        }

        $until = now()->addDays(max(1, $nearExpiryDays))->toDateString();
        $today = now()->toDateString();

        $rows = (clone $base)
            ->leftJoin('epc_ilmd', 'epc_ilmd.epc_id', '=', 'epcs.id')
            ->select([
                DB::raw(
                    "CASE WHEN epcs.epc_type = 'sscc' AND (epcs.gtin14 IS NULL OR epcs.gtin14 = '') "
                    ."THEN '".self::SSCC_PRODUCT_KEY."' "
                    .'ELSE COALESCE(NULLIF(epcs.gtin14, \'\'), NULLIF(epc_ilmd.gtin14, \'\'), \'\') END as gtin14'
                ),
                DB::raw("COALESCE(epc_ilmd.lot_number, '') as lot_number"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN EXISTS (
                    SELECT 1 FROM quarantine_holds qh
                    WHERE qh.epc_id = epcs.id AND qh.status = 'open'
                ) THEN 1 ELSE 0 END) as hold_count"),
                DB::raw("SUM(CASE WHEN epcs.epc_type = 'sgtin' THEN 1 ELSE 0 END) as sgtin_count"),
                DB::raw("SUM(CASE WHEN epcs.epc_type = 'sscc' THEN 1 ELSE 0 END) as sscc_count"),
                DB::raw('MIN(epc_ilmd.expiry_date) as min_expiry'),
                DB::raw('MAX(epc_ilmd.expiry_date) as max_expiry'),
            ])
            ->groupBy('gtin14', 'lot_number')
            ->orderBy('gtin14')
            ->orderBy('lot_number')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $gtin = (string) ($row->gtin14 ?? '');
            $lot = (string) ($row->lot_number ?? '');
            $total = (int) $row->total;
            $holdCount = (int) $row->hold_count;
            $minExpiry = $row->min_expiry !== null ? (string) $row->min_expiry : null;
            $maxExpiry = $row->max_expiry !== null ? (string) $row->max_expiry : null;
            $near = $minExpiry !== null
                && $minExpiry >= $today
                && $minExpiry <= $until;

            $out[] = [
                'gtin14' => $gtin,
                'lot_number' => $lot,
                'total' => $total,
                'pickable' => max(0, $total - $holdCount),
                'hold_count' => $holdCount,
                'sgtin_count' => (int) $row->sgtin_count,
                'sscc_count' => (int) $row->sscc_count,
                'min_expiry' => $minExpiry,
                'max_expiry' => $maxExpiry,
                'has_quarantine' => $holdCount > 0,
                'has_near_expiry' => $near,
            ];
        }

        return $out;
    }
}
