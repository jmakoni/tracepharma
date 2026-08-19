<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class InboundConnectionTradingPartner extends Pivot
{
    protected $table = 'inbound_connection_trading_partner';

    public $incrementing = true;

    protected $fillable = [
        'inbound_connection_id',
        'trading_partner_id',
        'sender_gln',
        'priority',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function inboundConnection(): BelongsTo
    {
        return $this->belongsTo(InboundConnection::class);
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }
}
