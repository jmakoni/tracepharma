<?php

namespace App\Models;

use App\Models\Exceptions\ExceptionCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verification extends Model
{
    protected $fillable = [
        'gtin14',
        'serial',
        'lot',
        'status',
        'scanned_barcode',
        'verified_by',
        'exception_id',
        'request_payload',
        'response_payload',
        'message',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ExceptionCase::class, 'exception_id');
    }
}
