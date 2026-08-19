<?php

declare(strict_types=1);

namespace App\Services\Labeling;

use App\Enums\SsccNumberRangeScope;
use App\Enums\SsccNumberRangeStatus;
use App\Exceptions\SsccNumberRangeCapacityException;
use App\Models\SsccLabel;
use App\Models\SsccNumberRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ResolveSsccNumberRange
{
    /**
     * Capacity-failure heals collected during {@see resolveAndIssue} retries. Persisted only
     * via {@see flushPendingHeals}, which callers must invoke after the outer allocation
     * transaction has committed or rolled back — persisting on MySQL while the outer
     * transaction still holds the range row lock self-deadlocks.
     *
     * @var list<SsccNumberRangeCapacityException>
     */
    private array $pendingHeals = [];

    /**
     * Prefer site → partner → tenant-wide active selectable range for prefix + extension.
     *
     * @param  list<int>  $excludeIds
     */
    public function resolve(
        string $companyPrefix,
        int $extensionDigit,
        ?int $siteId = null,
        ?int $tradingPartnerId = null,
        int $minRemaining = 1,
        array $excludeIds = [],
    ): ?SsccNumberRange {
        $extension = (string) $extensionDigit;
        $minRemaining = max(1, $minRemaining);

        if ($siteId !== null && $siteId > 0) {
            $siteRange = $this->firstWithCapacity(
                $this->baseQuery($companyPrefix, $extension)
                    ->where('scope', SsccNumberRangeScope::Site)
                    ->where('site_id', $siteId),
                $minRemaining,
                $excludeIds,
            );

            if ($siteRange !== null) {
                return $siteRange;
            }
        }

        if ($tradingPartnerId !== null && $tradingPartnerId > 0) {
            $partnerRange = $this->firstWithCapacity(
                $this->baseQuery($companyPrefix, $extension)
                    ->where('scope', SsccNumberRangeScope::Partner)
                    ->where('trading_partner_id', $tradingPartnerId),
                $minRemaining,
                $excludeIds,
            );

            if ($partnerRange !== null) {
                return $partnerRange;
            }
        }

        return $this->firstWithCapacity(
            $this->baseQuery($companyPrefix, $extension)
                ->where('scope', SsccNumberRangeScope::Tenant)
                ->whereNull('site_id')
                ->whereNull('trading_partner_id'),
            $minRemaining,
            $excludeIds,
        );
    }

    /**
     * @return array{0: SsccNumberRange, 1: list<int>}|null
     */
    public function resolveAndIssue(
        string $companyPrefix,
        int $extensionDigit,
        int $count,
        ?int $siteId = null,
        ?int $tradingPartnerId = null,
    ): ?array {
        $this->assertBatchSize($count);
        $excludeIds = [];
        $lastCapacityException = null;

        while (true) {
            $range = $this->resolve(
                $companyPrefix,
                $extensionDigit,
                $siteId,
                $tradingPartnerId,
                $count,
                $excludeIds,
            );

            if ($range === null) {
                // Exhausted every candidate range: surface the last capacity failure (correct
                // range id + message) instead of a bare null so callers can record it accurately.
                if ($lastCapacityException !== null) {
                    throw $lastCapacityException;
                }

                return null;
            }

            try {
                $serials = $this->issue($range, $count);

                return [$range->fresh() ?? $range, $serials];
            } catch (InvalidArgumentException $e) {
                if (! $this->isRetryableCapacityFailure($e)) {
                    throw $e;
                }

                if ($e instanceof SsccNumberRangeCapacityException) {
                    $this->pendingHeals[] = $e;
                    $lastCapacityException = $e;
                }

                $excludeIds[] = (int) $range->getKey();
            }
        }
    }

    /**
     * Persist any capacity-failure self-heals queued by {@see resolveAndIssue}. Must be called
     * once the outer allocation transaction has committed or rolled back — never while its
     * row lock on the number range is still held, or the heal connection deadlocks on MySQL.
     */
    public function flushPendingHeals(): void
    {
        if ($this->pendingHeals === []) {
            return;
        }

        $heals = $this->pendingHeals;
        $this->pendingHeals = [];

        foreach ($heals as $exception) {
            $exception->persistHeal();
        }
    }

    /**
     * @return list<int>
     */
    public function issue(SsccNumberRange $range, int $count): array
    {
        $this->assertBatchSize($count);

        // Does NOT persist a heal on capacity failure — the caller (resolveAndIssue) may still
        // be inside an outer transaction holding this row's lock. Persisting here on a second
        // connection would self-deadlock on MySQL. Heal attributes travel on the exception and
        // are flushed by the caller only after the outer transaction has ended.
        return DB::transaction(function () use ($range, $count): array {
            /** @var SsccNumberRange $locked */
            $locked = SsccNumberRange::query()
                ->whereKey($range->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $wasActive = $locked->status === SsccNumberRangeStatus::Active;
            self::advanceCurrentPastExistingLabels($locked);
            $locked->syncRemainingAndStatus();

            $available = (int) $locked->remaining;

            if ($locked->status !== SsccNumberRangeStatus::Active || $available < $count) {
                throw new SsccNumberRangeCapacityException(
                    "SSCC number range \"{$locked->name}\" does not have enough remaining serials (need {$count}, have {$available}).",
                    (int) $locked->getKey(),
                    $this->healAttributes($locked, $wasActive),
                );
            }

            $increment = max(1, (int) $locked->increment_by);
            $cursor = (int) $locked->current_number;
            $lastIssuable = $locked->lastIssuableNumber();
            $scanWindow = max(1, (int) config('sscc.max_sequential_scan', 100_000));
            $windowEnd = -1;
            $used = [];
            $serials = [];

            while (count($serials) < $count) {
                if ($cursor > $lastIssuable) {
                    $locked->current_number = $cursor;
                    $locked->syncRemainingAndStatus();

                    throw new SsccNumberRangeCapacityException(
                        "SSCC number range \"{$locked->name}\" is exhausted before fulfilling the request.",
                        (int) $locked->getKey(),
                        $this->healAttributes($locked, $wasActive),
                    );
                }

                if ($cursor > $windowEnd) {
                    $windowEnd = min($lastIssuable, $cursor + $scanWindow - 1);
                    $used = self::usedSerialMap(
                        (string) $locked->company_prefix,
                        (string) $locked->extension_digit,
                        $cursor,
                        $windowEnd,
                    );
                }

                if (! isset($used[$cursor])) {
                    $serials[] = $cursor;
                }

                $cursor += $increment;
            }

            $locked->current_number = $cursor;
            $locked->syncRemainingAndStatus();

            if ($wasActive && $locked->status === SsccNumberRangeStatus::Depleted) {
                $locked->threshold_notified_at = null;
            }

            $locked->save();

            return $serials;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function healAttributes(SsccNumberRange $range, bool $wasActive): array
    {
        $justDepleted = $wasActive && $range->status === SsccNumberRangeStatus::Depleted;

        return [
            'current_number' => (int) $range->current_number,
            'remaining' => (int) $range->remaining,
            'status' => $range->status instanceof SsccNumberRangeStatus
                ? $range->status->value
                : (string) $range->status,
            'threshold_notified_at' => $justDepleted ? null : $range->threshold_notified_at,
            'updated_at' => now(),
        ];
    }

    /**
     * Advance current_number past every existing label on the arithmetic sequence (handles gaps).
     * Walks the band in bounded windows instead of loading the whole [current, last] map, so
     * huge bands don't pull every label serial into memory at once.
     */
    public static function advanceCurrentPastExistingLabels(SsccNumberRange $range): void
    {
        $increment = max(1, (int) $range->increment_by);
        $cursor = (int) $range->current_number;
        $last = $range->lastIssuableNumber();

        if ($cursor > $last) {
            return;
        }

        $scanWindow = max(1, (int) config('sscc.max_sequential_scan', 100_000));
        $windowEnd = -1;
        $used = [];

        while ($cursor <= $last) {
            if ($cursor > $windowEnd) {
                $windowEnd = min($last, $cursor + $scanWindow - 1);
                $used = self::usedSerialMap(
                    (string) $range->company_prefix,
                    (string) $range->extension_digit,
                    $cursor,
                    $windowEnd,
                );
            }

            if (! isset($used[$cursor])) {
                break;
            }

            $cursor += $increment;
        }

        $range->current_number = $cursor;
    }

    /**
     * @return array<int, true>
     */
    public static function usedSerialMap(
        string $companyPrefix,
        string $extensionDigit,
        int $from,
        int $to,
    ): array {
        if ($from > $to) {
            return [];
        }

        return SsccLabel::query()
            ->where('company_prefix', $companyPrefix)
            ->where('extension_digit', $extensionDigit)
            ->whereBetween('serial_reference_int', [$from, $to])
            ->pluck('serial_reference_int')
            ->mapWithKeys(fn (int $serial): array => [$serial => true])
            ->all();
    }

    private function assertBatchSize(int $count): void
    {
        if ($count < 1) {
            throw new InvalidArgumentException('Label count must be at least 1.');
        }

        $max = (int) config('sscc.max_batch_size', 50);
        if ($count > $max) {
            throw new InvalidArgumentException("Label count cannot exceed {$max}.");
        }
    }

    private function isRetryableCapacityFailure(InvalidArgumentException $e): bool
    {
        if ($e instanceof SsccNumberRangeCapacityException) {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'does not have enough remaining serials')
            || str_contains($message, 'is exhausted before fulfilling');
    }

    /**
     * @param  Builder<SsccNumberRange>  $query
     * @param  list<int>  $excludeIds
     */
    private function firstWithCapacity($query, int $minRemaining, array $excludeIds = []): ?SsccNumberRange
    {
        if ($excludeIds !== []) {
            $query->whereKeyNot($excludeIds);
        }

        $query->orderBy('index')->orderBy('id');
        $chunkSize = 25;
        $page = 1;

        while (true) {
            /** @var list<SsccNumberRange> $candidates */
            $candidates = (clone $query)->forPage($page, $chunkSize)->get()->all();

            if ($candidates === []) {
                return null;
            }

            foreach ($candidates as $candidate) {
                if ($candidate->hasIssuableCapacity($minRemaining)) {
                    return $candidate;
                }
            }

            if (count($candidates) < $chunkSize) {
                return null;
            }

            $page++;
        }
    }

    /**
     * @return Builder<SsccNumberRange>
     */
    private function baseQuery(string $companyPrefix, string $extensionDigit)
    {
        return SsccNumberRange::query()
            ->activeSelectable()
            ->where('company_prefix', $companyPrefix)
            ->where('extension_digit', $extensionDigit);
    }
}
