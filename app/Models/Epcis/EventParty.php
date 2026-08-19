<?php

namespace App\Models\Epcis;

use App\Models\Site;
use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventParty extends Model
{
    protected $table = 'event_parties';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'party_role',
        'gln',
        'gln_uri',
        'trading_partner_id',
        'site_id',
        'extra_json',
    ];

    protected function casts(): array
    {
        return [
            'extra_json' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EpcisEvent::class, 'event_id');
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
