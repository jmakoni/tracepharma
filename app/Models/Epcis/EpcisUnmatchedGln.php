<?php

namespace App\Models\Epcis;

use App\Models\Site;
use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisUnmatchedGln extends Model
{
    protected $table = 'epcis_unmatched_glns';

    protected $fillable = [
        'document_id',
        'gln',
        'gln_uri',
        'context',
        'trading_partner_id',
        'site_id',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
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
