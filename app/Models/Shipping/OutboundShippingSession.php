<?php

namespace App\Models\Shipping;

use App\Models\Epcis\EpcisDocument;
use App\Models\OutboundConnection;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutboundShippingSession extends Model
{
    protected $table = 'outbound_shipping_sessions';

    protected $fillable = [
        'site_id',
        'trading_partner_id',
        'ship_to_site_id',
        'ship_to_gln',
        'outbound_connection_id',
        'status',
        'is_corrective',
        'corrective_reason',
        'corrects_epcis_document_id',
        'asn_number',
        'customer_po',
        'invoice_number',
        'shipment_reference',
        'dscsa_affirm',
        'expected_count',
        'confirmed_count',
        'epcis_document_id',
        'shipping_events_generated_at',
        'opened_by',
        'opened_at',
        'completed_at',
        'cancelled_at',
        'notes',
        'wms_idempotency_key',
        'wms_complete',
    ];

    protected function casts(): array
    {
        return [
            'dscsa_affirm' => 'boolean',
            'is_corrective' => 'boolean',
            'wms_complete' => 'boolean',
            'expected_count' => 'integer',
            'confirmed_count' => 'integer',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'shipping_events_generated_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function shipToSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'ship_to_site_id');
    }

    public function outboundConnection(): BelongsTo
    {
        return $this->belongsTo(OutboundConnection::class);
    }

    public function epcisDocument(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'epcis_document_id');
    }

    /**
     * The already-transmitted shipping document this corrective order amends, when known.
     */
    public function correctsDocument(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'corrects_epcis_document_id');
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function scanLines(): HasMany
    {
        return $this->hasMany(OutboundShippingScanLine::class, 'outbound_shipping_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['open', 'in_progress'], true);
    }

    public function canScan(): bool
    {
        return $this->isActive();
    }

    public function canSend(): bool
    {
        return $this->isActive() || $this->needsShippingEpcis();
    }

    /**
     * True when a prior completion marked the session as completed but the
     * EPCIS authoring step never finished (e.g. the process died mid-request).
     * The session is not active, but Send must remain retryable rather than
     * leaving the order in a dead-end state.
     */
    public function needsShippingEpcis(): bool
    {
        return $this->status === 'completed' && $this->shipping_events_generated_at === null;
    }

    public function canCancel(): bool
    {
        return $this->isActive() && $this->epcis_document_id === null;
    }

    public function canUnconfirmScanLines(): bool
    {
        return ($this->canScan() || $this->needsShippingEpcis())
            && $this->shipping_events_generated_at === null
            && $this->epcis_document_id === null;
    }
}
