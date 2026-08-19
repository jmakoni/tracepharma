<?php

namespace App\Models\Fda;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FdaProductPharmClass extends FdaModel
{
    public $timestamps = false;

    protected $fillable = [
        'product_id_fk',
        'class_name',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(FdaProduct::class, 'product_id_fk');
    }
}
