<?php

namespace App\Models\Receiving;

use App\Models\Epcis\EpcisDocument;
use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundShipment extends Model
{
    protected $table = 'inbound_shipments';

    protected $fillable = [
        'trading_partner_id',
        'trading_partner_key',
        'asn_number',
        'customer_po',
        'status',
        'document_count',
    ];

    protected function casts(): array
    {
        return [
            'trading_partner_key' => 'integer',
            'document_count' => 'integer',
        ];
    }

    public static function partnerKey(?int $tradingPartnerId): int
    {
        return $tradingPartnerId !== null && $tradingPartnerId > 0 ? $tradingPartnerId : 0;
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class, 'trading_partner_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EpcisDocument::class, 'inbound_shipment_id');
    }

    public function receivingSessions(): HasMany
    {
        return $this->hasMany(ReceivingSession::class, 'inbound_shipment_id');
    }
}
