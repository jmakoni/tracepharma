<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisJobMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'epcis_job_id',
        'level',
        'message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EpcisJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(EpcisJob::class, 'epcis_job_id');
    }
}
