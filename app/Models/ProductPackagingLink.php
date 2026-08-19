<?php

namespace App\Models;

use App\Enums\PackLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPackagingLink extends Model
{
    protected $fillable = [
        'parent_product_id',
        'child_product_id',
        'quantity',
        'pack_level',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'pack_level' => PackLevel::class,
        ];
    }

    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function childProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'child_product_id');
    }
}
