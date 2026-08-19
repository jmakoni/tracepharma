<?php

namespace App\Support\Fda;

use App\Models\Fda\FdaWdd3plImportRun;
use App\Models\Fda\FdaWdd3plStaging;

/**
 * How much of the FDA WDD/3PL report the staging table currently holds, measured
 * against the last import that finished.
 *
 * A report that suddenly carries under half the rows of the last good one is far
 * more likely to be a truncated download than an industry-wide collapse, and
 * promoting it would delist every facility the file lost.
 */
final class FdaStagingSnapshotSize
{
    public const MIN_ROW_RATIO_OF_PREVIOUS = 0.5;

    public function __construct(
        public readonly int $currentRows,
        public readonly int $previousRows,
    ) {}

    /**
     * @param  int|null  $currentRows  Rows this load inserted; the staging count when omitted.
     * @param  int|null  $importRunId  The run that loaded them; the latest run when omitted.
     */
    public static function measure(?int $currentRows = null, ?int $importRunId = null): self
    {
        $runId = $importRunId ?? FdaWdd3plImportRun::latestRun()?->getKey();
        $previous = FdaWdd3plImportRun::latestCompletedBefore($runId !== null ? (int) $runId : null);

        return new self(
            $currentRows ?? FdaWdd3plStaging::query()->count(),
            (int) ($previous?->row_count ?? 0),
        );
    }

    /**
     * False whenever there is nothing to compare against: a first import cannot be
     * judged short.
     */
    public function hasCollapsed(): bool
    {
        if ($this->previousRows <= 0) {
            return false;
        }

        return $this->currentRows < $this->floor();
    }

    public function floor(): int
    {
        return (int) floor($this->previousRows * self::MIN_ROW_RATIO_OF_PREVIOUS);
    }

    public function summary(): string
    {
        return "Staging holds {$this->currentRows} row(s); the previous import loaded {$this->previousRows}"
            .' (under '.(int) (self::MIN_ROW_RATIO_OF_PREVIOUS * 100).'%).';
    }
}
