<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsccLabelChild extends Model
{
    protected $fillable = [
        'sscc_label_id',
        'child_epc',
    ];

    public function label(): BelongsTo
    {
        return $this->belongsTo(SsccLabel::class, 'sscc_label_id');
    }
}
