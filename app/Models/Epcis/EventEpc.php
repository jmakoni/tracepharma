<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventEpc extends Model
{
    protected $table = 'event_epcs';

    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'epc_id',
        'role',
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

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'epc_id');
    }
}
