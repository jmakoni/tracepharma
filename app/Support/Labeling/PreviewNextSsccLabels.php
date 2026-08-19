<?php

namespace App\Support\Labeling;

use App\Models\SsccLabel;
use App\Models\SsccNumberRange;
use App\Models\SsccSerialPool;
use App\Services\Labeling\ResolveSsccNumberRange;
use App\Services\Labeling\SsccBuilder;
use App\Support\TenantSsccSettings;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Read-only preview of the next sequential parent SSCC(s) without allocating pool serials.
 */
final class PreviewNextSsccLabels
{
    public function __construct(
        private readonly SsccBuilder $ssccBuilder,
        private readonly ResolveSsccNumberRange $resolveSsccNumberRange,
    ) {}

    /**
     * @return list<string> SSCC-18 values
     */
    public function handle(
        int $labelCount = 1,
        ?string $companyPrefix = null,
        ?int $extensionDigit = null,
        ?int $siteId = null,
        ?int $tradingPartnerId = null,
    ): array {
        $settings = TenantSsccSettings::resolve();
        $prefix = $companyPrefix !== null && $companyPrefix !== ''
            ? $companyPrefix
            : ($settings['company_prefix'] ?? null);

        if ($prefix === null || $prefix === '') {
            throw new InvalidArgumentException('Configure a GS1 Company Prefix in Settings before packing.');
        }

        $prefix = $this->ssccBuilder->normalizeCompanyPrefix($prefix);
        $extension = $extensionDigit ?? (int) ($settings['extension_digit'] ?? 0);
        $maxBatch = (int) config('sscc.max_batch_size', 50);
        $count = max(1, min($maxBatch, $labelCount));

        $anyApplicable = $this->resolveSsccNumberRange->resolve(
            $prefix,
            $extension,
            $siteId,
            $tradingPartnerId,
            1,
        );
        $range = $this->resolveSsccNumberRange->resolve(
            $prefix,
            $extension,
            $siteId,
            $tradingPartnerId,
            $count,
        );

        if ($range === null) {
            if ((bool) config('sscc.require_number_range', false)) {
                throw new InvalidArgumentException(
                    'Configure an active SSCC number range in Settings before generating labels.',
                );
            }

            if ($anyApplicable !== null) {
                throw new InvalidArgumentException(
                    'Active SSCC number range(s) apply but none have enough remaining serials for this preview count.',
                );
            }
        }

        if ($range !== null) {
            return $this->previewFromRange($range, $prefix, $extension, $count);
        }

        return $this->previewFromPool($prefix, $extension, $count);
    }

    /**
     * @return list<string>
     */
    private function previewFromRange(
        SsccNumberRange $range,
        string $prefix,
        int $extension,
        int $count,
    ): array {
        $increment = max(1, (int) $range->increment_by);
        $cursor = (int) $range->current_number;
        $last = $range->lastIssuableNumber();
        $used = ResolveSsccNumberRange::usedSerialMap(
            (string) $range->company_prefix,
            (string) $range->extension_digit,
            $cursor,
            $last,
        );
        $previews = [];

        while (count($previews) < $count) {
            if ($cursor > $last) {
                throw new InvalidArgumentException(
                    "SSCC number range \"{$range->name}\" does not have enough remaining serials for this preview.",
                );
            }

            if (! isset($used[$cursor])) {
                $built = $this->ssccBuilder->build($prefix, $cursor, $extension);
                $previews[] = $built['sscc_18'];
            }

            $cursor += $increment;
        }

        return $previews;
    }

    /**
     * @return list<string>
     */
    private function previewFromPool(string $prefix, int $extension, int $count): array
    {
        $last = $this->lastSerialReference($prefix, $extension);
        $maxSerial = $this->ssccBuilder->maxSerialReferenceForPrefix($prefix);
        $reserved = SsccNumberRange::query()
            ->where('company_prefix', $prefix)
            ->where('extension_digit', (string) $extension)
            ->get(['start_number', 'range_size', 'increment_by', 'current_number', 'status']);

        $floor = $last + 1;
        $scanWindow = max(1, (int) config('sscc.max_sequential_scan', 100_000));
        $previews = [];
        $cursor = $floor;
        $windowEnd = -1;
        $used = [];

        while (count($previews) < $count) {
            if ($cursor > $maxSerial) {
                throw new InvalidArgumentException('Sequential preview would exceed the maximum serial reference for this company prefix.');
            }

            if ($this->isReservedByNumberRange($cursor, $reserved)) {
                $cursor = $this->skipPastReserved($cursor, $reserved);

                continue;
            }

            if ($cursor > $windowEnd) {
                $windowEnd = min($maxSerial, $cursor + $scanWindow - 1);
                $used = ResolveSsccNumberRange::usedSerialMap($prefix, (string) $extension, $cursor, $windowEnd);
            }

            if (! isset($used[$cursor])) {
                $built = $this->ssccBuilder->build($prefix, $cursor, $extension);
                $previews[] = $built['sscc_18'];
            }

            $cursor++;
        }

        return $previews;
    }

    /**
     * Reserves the entire contiguous band [start, reservedLastNumber] for a number range, not
     * just the on-grid points, matching SsccSerialAllocator's reservation semantics.
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
     * last reserved number instead of advancing one serial at a time.
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

    private function lastSerialReference(string $companyPrefix, int $extensionDigit): int
    {
        $pool = SsccSerialPool::query()
            ->where('company_prefix', $companyPrefix)
            ->where('extension_digit', (string) $extensionDigit)
            ->first();

        if ($pool !== null) {
            return (int) $pool->last_serial_reference_int;
        }

        return (int) (SsccLabel::query()
            ->where('company_prefix', $companyPrefix)
            ->where('extension_digit', (string) $extensionDigit)
            ->max('serial_reference_int') ?? 0);
    }
}
