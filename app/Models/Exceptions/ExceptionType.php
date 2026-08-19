<?php

namespace App\Models\Exceptions;

use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionTypeCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExceptionType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'hda_class',
        'description',
        'default_severity',
        'receive_impact',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExceptionTypeCategory::class,
            'default_severity' => ExceptionSeverity::class,
            'receive_impact' => ExceptionReceiveImpact::class,
            'is_active' => 'boolean',
        ];
    }

    public function blocksReceiving(): bool
    {
        return ($this->receive_impact ?? ExceptionReceiveImpact::Warning)->blocksReceiving();
    }

    public function cases(): HasMany
    {
        return $this->hasMany(ExceptionCase::class, 'exception_type_id');
    }

    public function slaRules(): HasMany
    {
        return $this->hasMany(ExceptionSlaRule::class, 'exception_type_id');
    }
}
