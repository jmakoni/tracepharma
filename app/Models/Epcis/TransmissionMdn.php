<?php

namespace App\Models\Epcis;

use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransmissionMdn extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'transmission_mdns';

    protected $fillable = [
        'document_id',
        'trading_partner_id',
        'mdn_status',
        'mdn_received_at',
        'mdn_payload',
    ];

    protected function casts(): array
    {
        return [
            'mdn_received_at' => 'datetime',
            'mdn_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }
}
