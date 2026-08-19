<?php

namespace App\Models\Quarantine;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuarantineHold extends Model
{
    protected $table = 'quarantine_holds';

    protected $fillable = [
        'epc_id',
        'document_id',
        'exception_id',
        'reason',
        'status',
        'severity',
        'opened_at',
        'closed_at',
        'closed_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'epc_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ExceptionCase::class, 'exception_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
