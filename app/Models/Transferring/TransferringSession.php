<?php

namespace App\Models\Transferring;

use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\User;
use App\Support\Floor\UnsubmittedSessionDelete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TransferringSession extends Model
{
    protected $table = 'transferring_sessions';

    protected $fillable = [
        'from_site_id',
        'to_site_id',
        'status',
        'confirmed_count',
        'received_count',
        'opened_by',
        'opened_at',
        'shipped_at',
        'received_at',
        'completed_at',
        'transfer_epcis_document_id',
        'transfer_events_generated_at',
        'receive_events_generated_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_count' => 'integer',
            'received_count' => 'integer',
            'opened_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'completed_at' => 'datetime',
            'transfer_events_generated_at' => 'datetime',
            'receive_events_generated_at' => 'datetime',
        ];
    }

    public function fromSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'from_site_id');
    }

    public function toSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'to_site_id');
    }

    public function transferDocument(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'transfer_epcis_document_id');
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function scanLines(): HasMany
    {
        return $this->hasMany(TransferringScanLine::class, 'transferring_session_id');
    }

    public function receivingSession(): HasOne
    {
        return $this->hasOne(ReceivingSession::class, 'transferring_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function canScan(): bool
    {
        return $this->isOpen();
    }

    public function canCancel(): bool
    {
        return $this->status === 'open'
            && $this->transfer_events_generated_at === null
            && $this->transfer_epcis_document_id === null;
    }

    public function canHardDelete(): bool
    {
        return UnsubmittedSessionDelete::canHardDeleteTransfer($this);
    }

    public function canUnconfirmScanLines(): bool
    {
        return $this->isOpen() && $this->transfer_events_generated_at === null;
    }
}
