<?php

namespace App\Support\Fda;

use App\Models\Fda\FdaProduct;
use App\Models\Product;

final class ScheduledProductPresence
{
    private const RANK = ['CII' => 4, 'CIII' => 3, 'CIV' => 2, 'CV' => 1];

    /**
     * @param  list<string>  $gtins
     * @return array{highest: ?string, has_scheduled: bool}
     */
    public static function forGtins(array $gtins): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $g): string => preg_replace('/\D+/', '', $g) ?? '',
            $gtins,
        ))));

        if ($normalized === []) {
            return ['highest' => null, 'has_scheduled' => false];
        }

        $fdaIds = Product::query()
            ->whereIn('gtin', $normalized)
            ->whereNotNull('fda_product_id')
            ->pluck('fda_product_id')
            ->unique()
            ->values()
            ->all();

        if ($fdaIds === []) {
            return ['highest' => null, 'has_scheduled' => false];
        }

        $schedules = FdaProduct::query()
            ->whereIn('id', $fdaIds)
            ->pluck('dea_schedule');

        $highest = null;
        $highestRank = 0;
        foreach ($schedules as $raw) {
            $label = FdaRegistryStatus::deaScheduleLabel(is_string($raw) ? $raw : null);
            $rank = $label !== null ? (self::RANK[$label] ?? 0) : 0;
            if ($rank > $highestRank) {
                $highestRank = $rank;
                $highest = $label;
            }
        }

        return ['highest' => $highest, 'has_scheduled' => $highest !== null];
    }
}
