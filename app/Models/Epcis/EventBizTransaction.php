<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventBizTransaction extends Model
{
    protected $table = 'event_biz_transactions';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'type_uri',
        'value',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(EpcisEvent::class, 'event_id');
    }
}
