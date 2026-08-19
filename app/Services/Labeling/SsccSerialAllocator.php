<?php

namespace App\Services\Labeling;

use App\DTOs\Labeling\SsccAllocationRequest;
use App\Enums\SsccAllocationMode;
use App\Models\SsccLabel;
use App\Models\SsccNumberRange;
use App\Models\SsccSerialPool;
use Illuminate\Support\Collection;

class SsccSerialAllocator
{
    public function __construct(
        private readonly SsccBuilder $ssccBuilder,
    ) {}

    /**
     * @return list<int>
     */
    public function allocate(SsccAllocationRequest $request, SsccSerialPool $pool): array
    {
        $this->validateLabelCount($request->labelCount);

        $maxSerial = $this->ssccBuilder->maxSerialReferenceForPrefix($request->companyPrefix);
        $last = $pool->last_serial_reference_int;
        $reservedRanges = $this->activeNumberRanges($pool);

        $serials = match ($request->mode) {
            SsccAllocationMode::Sequential => $this->allocateSequential($request, $last, $maxSerial, $reservedRanges, $pool),
            SsccAllocationMode::Range => $this->allocateRange($request, $last, $maxSerial, $pool, $reservedRanges),
            SsccAllocationMode::PartialRandom => $this->allocatePartialRandom($request, $last, $maxSerial, $pool, $reservedRanges),
            SsccAllocationMode::FullyRandom => $this->allocateFullyRandom($request, $last, $maxSerial, $pool, $reservedRanges),
        };

        if (count($serials) !== $request->labelCount) {
            throw new \InvalidArgumentException('Unable to allocate the requested number of SSCC serial references.');
        }

        return $serials;
    }

    /**
     * @param  Collection<int, SsccNumberRange>  $reservedRanges
     * @return list<int>
     */
    private function allocateSequential(
        SsccAllocationRequest $request,
        int $last,
        int $maxSerial,
        Collection $reservedRanges,
        SsccSerialPool $pool,
    ): array {
        $floor = $request->enforceForwardOnly ? $last + 1 : 0;
        $scanWindow = max(1, (int) config('sscc.max_sequential_scan', 100_000));
        $serials = [];
        $cursor = $floor;
        $windowEnd = -1;
        $used = [];

        while (count($serials) < $request->labelCount) {
            if ($cursor > $maxSerial) {
                throw new \InvalidArgumentException('Sequential allocation would exceed the maximum serial reference for this company prefix.');
            }

            if ($this->isReservedByNumberRange($cursor, $reservedRanges)) {
                $cursor = $this->skipPastReserved($cursor, $reservedRanges);

                continue;
            }

            if ($cursor > $windowEnd) {
                $windowEnd = min($maxSerial, $cursor + $scanWindow - 1);
                $used = $this->usedSerials($pool, $cursor, $windowEnd);
            }

            if (! isset($used[$cursor])) {
                $serials[] = $cursor;
            }

            $cursor++;
        }

        return $serials;
    }

    /**
     * @param  Collection<int, SsccNumberRange>  $reservedRanges
     * @return list<int>
     */
    private function allocateRange(
        SsccAllocationRequest $request,
        int $last,
        int $maxSerial,
        SsccSerialPool $pool,
        Collection $reservedRanges,
    ): array {
        if ($request->rangeStart === null) {
            throw new \InvalidArgumentException('Range start is required for range allocation.');
        }

        $start = $request->rangeStart;
        $end = $request->rangeEnd ?? ($start + $request->labelCount - 1);

        if ($start > $end) {
            throw new \InvalidArgumentException('Range start must be less than or equal to range end.');
        }

        if ($end - $start + 1 < $request->labelCount) {
            throw new \InvalidArgumentException('The requested range is too small for the label count.');
        }

        if ($request->enforceForwardOnly && $start <= $last) {
            throw new \InvalidArgumentException("Range start ({$start}) must be greater than the last generated serial ({$last}).");
        }

        if ($end > $maxSerial) {
            throw new \InvalidArgumentException('Range end exceeds the maximum serial reference for this company prefix.');
        }

        $serials = range($start, $start + $request->labelCount - 1);
        $this->assertUnused($serials, $pool);
        $this->assertNotReserved($serials, $reservedRanges);

        return $serials;
    }

    /**
     * @param  Collection<int, SsccNumberRange>  $reservedRanges
     * @return list<int>
     */
    private function allocatePartialRandom(
        SsccAllocationRequest $request,
        int $last,
        int $maxSerial,
        SsccSerialPool $pool,
        Collection $reservedRanges,
    ): array {
        $prefix = $request->fixedPrefix;

        if ($prefix === null || $prefix === '') {
            throw new \InvalidArgumentException('Fixed prefix is required for partial random allocation.');
        }

        if (! ctype_digit($prefix)) {
            throw new \InvalidArgumentException('Fixed prefix must contain digits only.');
        }

        $width = 16 - strlen($request->companyPrefix);
        $suffixLength = $width - strlen($prefix);

        if ($suffixLength <= 0) {
            throw new \InvalidArgumentException('Fixed prefix fills the entire serial reference field.');
        }

        $bandMin = (int) ($prefix.str_repeat('0', $suffixLength));
        $bandMax = (int) ($prefix.str_repeat('9', $suffixLength));
        $floor = $request->enforceForwardOnly ? max($bandMin, $last + 1) : $bandMin;
        $ceiling = min($bandMax, $request->randomCeiling ?? $bandMax);

        if ($floor > $ceiling) {
            throw new \InvalidArgumentException('No available serial references remain in the partial random prefix band.');
        }

        return $this->pickUniqueRandomInts(
            count: $request->labelCount,
            floor: $floor,
            ceiling: $ceiling,
            pool: $pool,
            prefix: $prefix,
            width: $width,
            reservedRanges: $reservedRanges,
        );
    }

    /**
     * @param  Collection<int, SsccNumberRange>  $reservedRanges
     * @return list<int>
     */
    private function allocateFullyRandom(
        SsccAllocationRequest $request,
        int $last,
        int $maxSerial,
        SsccSerialPool $pool,
        Collection $reservedRanges,
    ): array {
        $floor = $request->randomFloor ?? ($request->enforceForwardOnly ? $last + 1 : 0);
        $ceiling = min($request->randomCeiling ?? $maxSerial, $maxSerial);

        if ($floor > $ceiling) {
            throw new \InvalidArgumentException('No available serial references remain in the fully random range.');
        }

        return $this->pickUniqueRandomInts(
            count: $request->labelCount,
            floor: $floor,
            ceiling: $ceiling,
            pool: $pool,
            prefix: null,
            width: 16 - strlen($request->companyPrefix),
            reservedRanges: $reservedRanges,
        );
    }

    /**
     * @param  Collection<int, SsccNumberRange>  $reservedRanges
     * @return list<int>
     */
    private function pickUniqueRandomInts(
        int $count,
        int $floor,
        int $ceiling,
        SsccSerialPool $pool,
        ?string $prefix,
        int $width,
        Collection $reservedRanges,
    ): array {
        $used = $this->usedSerials($pool, $floor, $ceiling);
        $chosen = [];
        $attempts = 0;
        $maxAttempts = max($count * 50, 100);

        while (count($chosen) < $count && $attempts < $maxAttempts) {
            $attempts++;
            $candidate = random_int($floor, $ceiling);

            if ($prefix !== null && ! $this->serialMatchesPrefix($candidate, $prefix, $width)) {
                continue;
            }

            if (
                isset($used[$candidate])
                || in_array($candidate, $chosen, true)
                || $this->isReservedByNumberRange($candidate, $reservedRanges)
            ) {
                continue;
            }

            $chosen[] = $candidate;
        }

        if (count($chosen) < $count) {
            $span = $ceiling - $floor + 1;
            $maxScan = (int) config('sscc.max_random_scan', 100_000);

            if ($span > $maxScan) {
                throw new \InvalidArgumentException(
                    'Random allocation search space is too large or fully reserved by SSCC number ranges. Narrow the floor/ceiling or free a number range band.',
                );
            }

            $remaining = [];

            for ($candidate = $floor; $candidate <= $ceiling; $candidate++) {
                if (
                    isset($used[$candidate])
                    || in_array($candidate, $chosen, true)
                    || $this->isReservedByNumberRange($candidate, $reservedRanges)
                ) {
                    continue;
                }

                if ($prefix !== null && ! $this->serialMatchesPrefix($candidate, $prefix, $width)) {
                    continue;
                }

                $remaining[] = $candidate;
            }

            if (count($remaining) < $count - count($chosen)) {
                throw new \InvalidArgumentException('Not enough unused serial references remain in the requested range.');
            }

            shuffle($remaining);
            $chosen = array_merge($chosen, array_slice($remaining, 0, $count - count($chosen)));
        }

        sort($chosen);

        return $chosen;
    }

    /**
     * @return array<int, true>
     */
    private function usedSerials(SsccSerialPool $pool, int $floor, int $ceiling): array
    {
        return SsccLabel::query()
            ->where('company_prefix', $pool->company_prefix)
            ->where('extension_digit', $pool->extension_digit)
            ->whereBetween('serial_reference_int', [$floor, $ceiling])
            ->pluck('serial_reference_int')
            ->mapWithKeys(fn (int $serial): array => [$serial => true])
            ->all();
    }

    /**
     * @return Collection<int, SsccNumberRange>
     */
    private function activeNumberRanges(SsccSerialPool $pool): Collection
    {
        return SsccNumberRange::query()
            ->where('company_prefix', $pool->company_prefix)
            ->where('extension_digit', $pool->extension_digit)
            ->get(['start_number', 'range_size', 'increment_by', 'current_number', 'status']);
    }

    /**
     * Reserves the entire contiguous band [start, reservedLastNumber] for a number range, not
     * just the on-grid points. Off-grid serials inside the band are still owned by the range
     * (future increment changes, gaps, etc.) and must not be handed out by the pool allocator.
     *
     * @param  Collection<int, SsccNumberRange>  $reservedRanges
     */
    private function isReservedByNumberRange(int $serial, Collection $reservedRanges): bool
    {
        foreach ($reservedRanges as $range) {
            $start = (int) $range->start_number;
            $last = $range->reservedLastNumber();

            if ($last < $start || $serial < $start || $serial > $last) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * When $cursor falls inside a reserved number-range band, jump straight past that band's
     * last reserved number instead of advancing one serial at a time (bands can span up to
     * config('sscc.max_range_size') serials).
     *
     * @param  Collection<int, SsccNumberRange>  $reservedRanges
     */
    private function skipPastReserved(int $cursor, Collection $reservedRanges): int
    {
        $next = $cursor + 1;

        foreach ($reservedRanges as $range) {
            $start = (int) $range->start_number;
            $last = $range->reservedLastNumber();

            if ($last < $start || $cursor < $start || $cursor > $last) {
                continue;
            }

            $next = max($next, $last + 1);
        }

        return $next;
    }

    /**
     * @param  list<int>  $serials
     * @param  Collection<int, SsccNumberRange>  $reservedRanges
     */
    private function assertNotReserved(array $serials, Collection $reservedRanges): void
    {
        $blocked = array_values(array_filter(
            $serials,
            fn (int $serial): bool => $this->isReservedByNumberRange($serial, $reservedRanges),
        ));

        if ($blocked !== []) {
            throw new \InvalidArgumentException(
                'Serial reference '.implode(', ', $blocked).' is reserved by an SSCC number range.',
            );
        }
    }

    /**
     * @param  list<int>  $serials
     */
    private function assertUnused(array $serials, SsccSerialPool $pool): void
    {
        if ($serials === []) {
            return;
        }

        $existing = SsccLabel::query()
            ->where('company_prefix', $pool->company_prefix)
            ->where('extension_digit', $pool->extension_digit)
            ->whereIn('serial_reference_int', $serials)
            ->pluck('serial_reference_int')
            ->all();

        if ($existing !== []) {
            throw new \InvalidArgumentException('Serial reference '.implode(', ', $existing).' has already been generated.');
        }
    }

    private function serialMatchesPrefix(int $serial, string $prefix, int $width): bool
    {
        return str_starts_with(str_pad((string) $serial, $width, '0', STR_PAD_LEFT), $prefix);
    }

    private function validateLabelCount(int $labelCount): void
    {
        $max = (int) config('sscc.max_batch_size', 50);

        if ($labelCount < 1) {
            throw new \InvalidArgumentException('Label count must be at least 1.');
        }

        if ($labelCount > $max) {
            throw new \InvalidArgumentException("Label count cannot exceed {$max}.");
        }
    }
}
