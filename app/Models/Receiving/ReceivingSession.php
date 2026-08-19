<?php

namespace App\Models\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceivingSession extends Model
{
    protected $table = 'receiving_sessions';

    protected $fillable = [
        'session_kind',
        'epcis_document_id',
        'transferring_session_id',
        'receiving_epcis_document_id',
        'matched_epcis_document_id',
        'trading_partner_id',
        'site_id',
        'status',
        'expected_parent_count',
        'confirmed_parent_count',
        'expected_child_count',
        'confirmed_child_count',
        'opened_by',
        'opened_at',
        'completed_at',
        'receiving_events_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'session_kind' => ReceivingSessionKind::class,
            'expected_parent_count' => 'integer',
            'confirmed_parent_count' => 'integer',
            'expected_child_count' => 'integer',
            'confirmed_child_count' => 'integer',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'receiving_events_generated_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'epcis_document_id');
    }

    /**
     * Outbound EPCIS document generated for this session's receiving events (Phase 2).
     */
    public function receivingDocument(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'receiving_epcis_document_id');
    }

    public function matchedDocument(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'matched_epcis_document_id');
    }

    public function transferringSession(): BelongsTo
    {
        return $this->belongsTo(TransferringSession::class, 'transferring_session_id');
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function scanLines(): HasMany
    {
        return $this->hasMany(ReceivingScanLine::class, 'receiving_session_id');
    }

    public function isScanFirst(): bool
    {
        return $this->session_kind === ReceivingSessionKind::ScanFirst;
    }

    public function isTransferReceive(): bool
    {
        return $this->session_kind === ReceivingSessionKind::TransferReceive;
    }

    public function isInboundAsn(): bool
    {
        return $this->session_kind === ReceivingSessionKind::InboundAsn
            || $this->session_kind === null;
    }

    /**
     * Whether the session can be user-cancelled (Active → History).
     */
    public function canCancel(): bool
    {
        return in_array($this->status, ['open', 'in_progress'], true)
            && $this->receiving_events_generated_at === null
            && $this->receiving_epcis_document_id === null;
    }

    /**
     * Close an open/in-progress session so Ops Hub no longer treats it as active.
     */
    public function cancelOpen(): bool
    {
        if (! in_array($this->status, ['open', 'in_progress'], true)) {
            return false;
        }

        $this->forceFill([
            'status' => 'cancelled',
            'completed_at' => now(),
        ])->save();

        return true;
    }
}
