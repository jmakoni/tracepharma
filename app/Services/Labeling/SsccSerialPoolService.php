<?php

namespace App\Services\Labeling;

use App\Enums\SsccAllocationMode;
use App\Models\SsccLabel;
use App\Models\SsccSerialPool;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SsccSerialPoolService
{
    public function lockOrCreate(string $companyPrefix, int $extensionDigit): SsccSerialPool
    {
        $extension = (string) $extensionDigit;

        return DB::transaction(function () use ($companyPrefix, $extension): SsccSerialPool {
            $pool = SsccSerialPool::query()
                ->where('company_prefix', $companyPrefix)
                ->where('extension_digit', $extension)
                ->lockForUpdate()
                ->first();

            if ($pool !== null) {
                return $pool;
            }

            $lastFromLabels = (int) (SsccLabel::query()
                ->where('company_prefix', $companyPrefix)
                ->where('extension_digit', $extension)
                ->max('serial_reference_int') ?? 0);

            try {
                SsccSerialPool::query()->create([
                    'company_prefix' => $companyPrefix,
                    'extension_digit' => $extension,
                    'default_allocation_mode' => SsccAllocationMode::Sequential,
                    'last_serial_reference_int' => $lastFromLabels,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another worker created the pool row after our lockForUpdate miss.
            }

            return SsccSerialPool::query()
                ->where('company_prefix', $companyPrefix)
                ->where('extension_digit', $extension)
                ->lockForUpdate()
                ->firstOrFail();
        });
    }

    /**
     * @param  list<int>  $serials
     */
    public function updateHighWaterMark(SsccSerialPool $pool, array $serials): void
    {
        if ($serials === []) {
            return;
        }

        $pool->last_serial_reference_int = max($pool->last_serial_reference_int, ...$serials);
        $pool->save();
    }

    public function recordPrinted(SsccSerialPool $pool, int $serialReference, ?\DateTimeInterface $printedAt = null): void
    {
        if ($pool->last_printed_serial_reference_int !== null && $serialReference < $pool->last_printed_serial_reference_int) {
            return;
        }

        $pool->last_printed_serial_reference_int = max($pool->last_printed_serial_reference_int ?? 0, $serialReference);
        $pool->last_printed_at = $printedAt ?? now();
        $pool->save();
    }

    public function find(string $companyPrefix, int $extensionDigit): ?SsccSerialPool
    {
        return SsccSerialPool::query()
            ->where('company_prefix', $companyPrefix)
            ->where('extension_digit', (string) $extensionDigit)
            ->first();
    }
}
