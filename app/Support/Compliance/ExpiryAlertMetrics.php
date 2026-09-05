<?php

declare(strict_types=1);

namespace App\Support\Compliance;

use App\Models\Epcis\Epc;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Shipping\ShippableEpcsAtSite;
use Illuminate\Support\Collection;

/**
 * Tenant-wide on-hand expiry signals for Alert Center / digests.
 */
final class ExpiryAlertMetrics
{
    /**
     * @return array{expired: int, soon_30: int, soon_90: int}
     */
    public function counts(): array
    {
        $siteIds = EligibleReceiveSites::forOrganization()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($siteIds === []) {
            return ['expired' => 0, 'soon_30' => 0, 'soon_90' => 0];
        }

        $today = now()->toDateString();
        $until30 = now()->addDays(30)->toDateString();
        $until90 = now()->addDays(90)->toDateString();

        $onHandIds = $this->onHandEpcIds($siteIds);

        if ($onHandIds === []) {
            return ['expired' => 0, 'soon_30' => 0, 'soon_90' => 0];
        }

        $base = Epc::query()
            ->where('epcs.epc_type', 'sgtin')
            ->whereIn('epcs.id', $onHandIds)
            ->whereHas('ilmd', fn ($q) => $q->whereNotNull('expiry_date'));

        $expired = (clone $base)
            ->whereHas('ilmd', fn ($q) => $q->whereDate('expiry_date', '<', $today))
            ->count();

        $soon30 = (clone $base)
            ->whereHas('ilmd', function ($q) use ($today, $until30): void {
                $q->whereDate('expiry_date', '>=', $today)
                    ->whereDate('expiry_date', '<=', $until30);
            })
            ->count();

        $soon90 = (clone $base)
            ->whereHas('ilmd', function ($q) use ($today, $until90): void {
                $q->whereDate('expiry_date', '>=', $today)
                    ->whereDate('expiry_date', '<=', $until90);
            })
            ->count();

        return [
            'expired' => $expired,
            'soon_30' => $soon30,
            'soon_90' => $soon90,
        ];
    }

    /**
     * @param  list<int>  $siteIds
     * @return list<int>
     */
    private function onHandEpcIds(array $siteIds): array
    {
        /** @var Collection<int, int> $ids */
        $ids = collect();
        $shippable = app(ShippableEpcsAtSite::class);

        foreach ($siteIds as $siteId) {
            $chunk = $shippable->query($siteId)
                ->where('epcs.epc_type', 'sgtin')
                ->limit(5000)
                ->pluck('epcs.id');
            $ids = $ids->merge($chunk);
        }

        return $ids->unique()->values()->map(fn ($id): int => (int) $id)->all();
    }
}
