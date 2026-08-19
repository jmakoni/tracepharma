<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcIlmd extends Model
{
    protected $table = 'epc_ilmd';

    public $incrementing = false;

    protected $primaryKey = 'epc_id';

    public $timestamps = false;

    protected $fillable = [
        'epc_id',
        'gtin14',
        'lot_number',
        'expiry_date',
        'manufacturing_date',
        'best_before_date',
        'additional_id',
        'extra_json',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'manufacturing_date' => 'date',
            'best_before_date' => 'date',
            'extra_json' => 'array',
        ];
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'epc_id');
    }
}
