<?php

namespace App\Models\Fda;

use App\Support\Fda\FdaRegistryStatus;

class FdaImportRun extends FdaModel
{
    protected $fillable = [
        'source',
        'source_path',
        'sha256',
        'rows_read',
        'rows_inserted',
        'rows_updated',
        'rows_skipped',
        'rows_sent_to_review',
        'started_at',
        'completed_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'rows_read' => 'integer',
            'rows_inserted' => 'integer',
            'rows_updated' => 'integer',
            'rows_skipped' => 'integer',
            'rows_sent_to_review' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function outcome(): string
    {
        return FdaRegistryStatus::importRun($this);
    }
}
