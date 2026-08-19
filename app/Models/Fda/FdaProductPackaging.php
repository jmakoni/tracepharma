<?php

namespace App\Models\Fda;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FdaProductPackaging extends FdaModel
{
    protected $table = 'fda_product_packaging';

    protected $fillable = [
        'package_ndc',
        'fda_product_id',
        'description',
        'net_content_description',
        'marketing_start_date',
        'is_sample',
        'ndc11',
        'gtin',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'marketing_start_date' => 'date',
            'is_sample' => 'boolean',
            'is_active' => 'boolean',
            'manually_edited_fields' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(FdaProduct::class, 'fda_product_id');
    }
}
