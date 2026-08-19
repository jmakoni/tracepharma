<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Transferring\TransferringSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EpcisJob extends Model
{
    protected $fillable = [
        'receipt',
        'kind',
        'status',
        'epcis_document_id',
        'outbound_shipping_session_id',
        'receiving_session_id',
        'transferring_session_id',
        'sscc_label_batch_id',
        'outbound_connection_id',
        'ship_from_site_id',
        'requested_by',
        'original_filename',
        'received_at',
        'started_at',
        'finished_at',
        'archived_at',
        'processing_time_ms',
        'attempt_count',
        'error_message',
        'stats_json',
    ];

    protected function casts(): array
    {
        return [
            'kind' => EpcisJobKind::class,
            'status' => EpcisJobStatus::class,
            'received_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'archived_at' => 'datetime',
            'stats_json' => 'array',
            'attempt_count' => 'integer',
            'processing_time_ms' => 'integer',
        ];
    }

    /**
     * @param  Builder<EpcisJob>  $query
     * @return Builder<EpcisJob>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @return HasMany<EpcisJobMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EpcisJobMessage::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<EpcisDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'epcis_document_id');
    }

    /**
     * @return BelongsTo<OutboundShippingSession, $this>
     */
    public function shippingSession(): BelongsTo
    {
        return $this->belongsTo(OutboundShippingSession::class, 'outbound_shipping_session_id');
    }

    /**
     * @return BelongsTo<ReceivingSession, $this>
     */
    public function receivingSession(): BelongsTo
    {
        return $this->belongsTo(ReceivingSession::class, 'receiving_session_id');
    }

    /**
     * @return BelongsTo<TransferringSession, $this>
     */
    public function transferringSession(): BelongsTo
    {
        return $this->belongsTo(TransferringSession::class, 'transferring_session_id');
    }

    /**
     * @return BelongsTo<SsccLabelBatch, $this>
     */
    public function ssccLabelBatch(): BelongsTo
    {
        return $this->belongsTo(SsccLabelBatch::class, 'sscc_label_batch_id');
    }

    /**
     * @return BelongsTo<OutboundConnection, $this>
     */
    public function outboundConnection(): BelongsTo
    {
        return $this->belongsTo(OutboundConnection::class, 'outbound_connection_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function shipFromSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'ship_from_site_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
