<?php

namespace App\Models\Exceptions;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExceptionActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'exception_id',
        'user_id',
        'kind',
        'visibility',
        'body',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ExceptionActivityKind::class,
            'visibility' => ExceptionActivityVisibility::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ExceptionCase::class, 'exception_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
