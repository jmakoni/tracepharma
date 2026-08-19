<?php

declare(strict_types=1);

namespace App\Services\Labeling;

use App\Enums\SsccNumberRangeStatus;
use App\Models\SsccNumberRange;

final class SsccNumberRangeMonitorService
{
    /**
     * Ranges at or above threshold (including just-depleted) that have not been notified yet.
     *
     * @return list<array<string, mixed>>
     */
    public function rangesNeedingAlert(): array
    {
        return SsccNumberRange::query()
            ->whereIn('status', [
                SsccNumberRangeStatus::Active,
                SsccNumberRangeStatus::Depleted,
            ])
            ->whereNull('threshold_notified_at')
            ->with(['site:id,name', 'tradingPartner:id,name'])
            ->get()
            ->filter(function (SsccNumberRange $range): bool {
                if ($range->status === SsccNumberRangeStatus::Depleted) {
                    return true;
                }

                // Capacity-based, not pointer-based: a band whose remaining serials have been
                // eaten by externally-created labels must still alert.
                return $range->alertUtilizationPercentage() >= (float) $range->threshold_percentage;
            })
            ->map(fn (SsccNumberRange $range): array => [
                'range_id' => $range->id,
                'name' => $range->name,
                'scope' => $range->scope->value,
                'owner' => $range->ownerLabel(),
                'company_prefix' => $range->company_prefix,
                'extension_digit' => $range->extension_digit,
                'remaining' => $range->remaining,
                'range_size' => $range->range_size,
                'utilization_percentage' => round($range->alertUtilizationPercentage(), 1),
                'threshold_percentage' => $range->threshold_percentage,
                'has_gs1_api_key' => filled($range->gs1_api_key),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $rangeIds
     */
    public function markNotified(array $rangeIds): void
    {
        if ($rangeIds === []) {
            return;
        }

        // Eloquent updates (not a raw query builder update) so LogsActivity model events fire.
        SsccNumberRange::query()
            ->whereIn('id', $rangeIds)
            ->each(fn (SsccNumberRange $range) => $range->update(['threshold_notified_at' => now()]));
    }
}
