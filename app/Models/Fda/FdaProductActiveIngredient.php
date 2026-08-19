<?php

namespace App\Models\Fda;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FdaProductActiveIngredient extends FdaModel
{
    public $timestamps = false;

    protected $fillable = [
        'product_id_fk',
        'name',
        'strength',
    ];

    protected function casts(): array
    {
        return [
            'manually_edited_fields' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(FdaProduct::class, 'product_id_fk');
    }
}
