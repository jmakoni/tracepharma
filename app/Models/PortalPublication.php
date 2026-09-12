<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Epcis\EpcisDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalPublication extends Model
{
    protected $fillable = [
        'epcis_document_id',
        'trading_partner_id',
        'published_at',
        'published_by_connection_id',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @return BelongsTo<EpcisDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'epcis_document_id');
    }

    /**
     * @return BelongsTo<TradingPartner, $this>
     */
    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    /**
     * @return BelongsTo<OutboundConnection, $this>
     */
    public function publishedByConnection(): BelongsTo
    {
        return $this->belongsTo(OutboundConnection::class, 'published_by_connection_id');
    }
}
