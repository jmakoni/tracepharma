<?php

namespace App\Models\Epcis;

use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisException extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'epcis_exceptions';

    protected $fillable = [
        'case_id',
        'document_id',
        'event_id',
        'epc_id',
        'exception_type',
        'severity',
        'description',
        'status',
        'assigned_to',
        'user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ExceptionCase::class, 'case_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EpcisEvent::class, 'event_id');
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'epc_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
