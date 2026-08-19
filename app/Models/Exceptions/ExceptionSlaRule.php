<?php

namespace App\Models\Exceptions;

use App\Enums\ExceptionSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExceptionSlaRule extends Model
{
    protected $fillable = [
        'exception_type_id',
        'severity',
        'first_response_hours',
        'resolve_hours',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'severity' => ExceptionSeverity::class,
            'first_response_hours' => 'integer',
            'resolve_hours' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExceptionType::class, 'exception_type_id');
    }
}
