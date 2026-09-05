<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class OutboundConnectionTradingPartner extends Pivot
{
    protected $table = 'outbound_connection_trading_partner';

    public $incrementing = true;

    protected $fillable = [
        'outbound_connection_id',
        'trading_partner_id',
    ];

    public function outboundConnection(): BelongsTo
    {
        return $this->belongsTo(OutboundConnection::class);
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }
}
