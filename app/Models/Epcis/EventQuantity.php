<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventQuantity extends Model
{
    protected $table = 'event_quantities';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'role',
        'epc_class',
        'quantity',
        'uom',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EpcisEvent::class, 'event_id');
    }
}
