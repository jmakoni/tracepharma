<?php

declare(strict_types=1);

namespace App\Services\Labeling;

use App\Models\SsccSerialPool;
use App\Support\TenantSsccSettings;

class SsccPoolMonitorService
{
    public function __construct(
        private readonly int $defaultLowWaterMark = 5000,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function lowWaterPools(): array
    {
        return SsccSerialPool::query()
            ->get()
            ->map(fn (SsccSerialPool $pool): ?array => $this->evaluatePool($pool))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evaluatePool(SsccSerialPool $pool): ?array
    {
        $prefixLength = strlen((string) $pool->company_prefix);
        $serialRefLength = max(1, 16 - $prefixLength);
        $maxSerial = (10 ** $serialRefLength) - 1;
        $lowWater = (int) (TenantSsccSettings::resolve()['low_water_mark'] ?? $this->defaultLowWaterMark);
        $remaining = max(0, $maxSerial - (int) $pool->last_serial_reference_int);

        if ($remaining > $lowWater) {
            return null;
        }

        return [
            'pool_id' => $pool->id,
            'company_prefix' => $pool->company_prefix,
            'extension_digit' => $pool->extension_digit,
            'last_serial_reference_int' => $pool->last_serial_reference_int,
            'remaining_serials' => $remaining,
            'low_water_mark' => $lowWater,
            'max_serial_reference' => $maxSerial,
        ];
    }
}
