<?php

namespace App\Models\Fda;

use Illuminate\Database\Eloquent\Builder;

/**
 * One attempt at loading the FDA WDD/3PL report into fda_wdd_3pl_staging.
 *
 * The row is written before the file is streamed and stamped completed only after
 * the last staging insert, so a run left without completed_at is the record of a
 * half-loaded staging table — which promotion must not read as an FDA snapshot.
 */
class FdaWdd3plImportRun extends FdaModel
{
    protected $table = 'fda_wdd_3pl_import_runs';

    public $timestamps = false;

    protected $fillable = [
        'source_path',
        'sha256',
        'rows_read',
        'rows_matched',
        'rows_skipped_unmatched',
        'row_count',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'rows_read' => 'integer',
            'rows_matched' => 'integer',
            'rows_skipped_unmatched' => 'integer',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }

    public static function latestRun(): ?self
    {
        return self::query()->orderByDesc('id')->first();
    }

    /**
     * The newest completed run, optionally ignoring the run currently in hand so a
     * command can compare what it just loaded against the last good load.
     */
    public static function latestCompletedBefore(?int $runId = null): ?self
    {
        return self::query()
            ->completed()
            ->when($runId !== null, fn (Builder $query): Builder => $query->where('id', '<', $runId))
            ->orderByDesc('id')
            ->first();
    }
}
