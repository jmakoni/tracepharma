<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventEpcIlmd extends Model
{
    protected $table = 'event_epc_ilmd';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'epc_id',
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

    public function event(): BelongsTo
    {
        return $this->belongsTo(EpcisEvent::class, 'event_id');
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'epc_id');
    }
}
