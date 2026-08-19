<?php

namespace App\Models\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExceptionAction extends Model
{
    protected $table = 'exception_actions';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ExceptionCase::class, 'resolution_action_id');
    }
}
