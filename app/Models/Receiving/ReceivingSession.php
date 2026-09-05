<?php

namespace App\Models\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Floor\UnsubmittedSessionDelete;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceivingSession extends Model
{
    protected $table = 'receiving_sessions';

    protected $fillable = [
        'session_kind',
        'epcis_document_id',
        'inbound_shipment_id',
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
        'active_parent_epc_id',
        'short_closed_parent_epc_ids',
        'opened_by',
        'opened_at',
        'completed_at',
        'receiving_events_generated_at',
        'wms_receive_confirmed_at',
        'invoice_disk',
        'invoice_path',
        'invoice_original_filename',
        'invoice_sha256',
    ];

    protected function casts(): array
    {
        return [
            'session_kind' => ReceivingSessionKind::class,
            'expected_parent_count' => 'integer',
            'confirmed_parent_count' => 'integer',
            'expected_child_count' => 'integer',
            'confirmed_child_count' => 'integer',
            'active_parent_epc_id' => 'integer',
            'short_closed_parent_epc_ids' => 'array',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'receiving_events_generated_at' => 'datetime',
            'wms_receive_confirmed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'epcis_document_id');
    }

    public function inboundShipment(): BelongsTo
    {
        return $this->belongsTo(InboundShipment::class, 'inbound_shipment_id');
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

    public function activeParentEpc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'active_parent_epc_id');
    }

    /**
     * @return list<int>
     */
    public function shortClosedParentEpcIdList(): array
    {
        $ids = $this->short_closed_parent_epc_ids;
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function openToteLabel(?Epc $parent = null): string
    {
        $epc = $parent ?? $this->activeParentEpc;
        if ($epc === null && $this->active_parent_epc_id !== null) {
            $epc = Epc::query()->find($this->active_parent_epc_id);
        }

        if ($epc === null) {
            return '';
        }

        return filled($epc->sscc18) ? (string) $epc->sscc18 : (string) $epc->epc_uri;
    }

    public function hasUnclosedExpectedChildrenOfConfirmedParents(): bool
    {
        $shortClosed = $this->shortClosedParentEpcIdList();

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->getKey())
            ->where('line_role', 'child')
            ->where('status', 'expected')
            ->whereNotNull('parent_epc_id')
            ->when($shortClosed !== [], fn ($query) => $query->whereNotIn('parent_epc_id', $shortClosed))
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('receiving_scan_lines as parents')
                    ->whereColumn('parents.epc_id', 'receiving_scan_lines.parent_epc_id')
                    ->whereColumn('parents.receiving_session_id', 'receiving_scan_lines.receiving_session_id')
                    ->where('parents.line_role', 'parent')
                    ->where('parents.status', 'confirmed');
            })
            ->exists();
    }

    /**
     * Open-tote lock blocks complete only in open_tote, and only while the
     * locked parent still has expected children. Sealed / open_count must
     * auto-complete even if a leftover lock column is set. When every child
     * of the locked parent is already confirmed, complete is allowed.
     */
    public function openToteLockBlocksComplete(): bool
    {
        if ($this->active_parent_epc_id === null) {
            return false;
        }

        if (ReceivingPolicy::forTenant(tenant())->edgeMode() !== ReceivingEdgeMode::OpenTote) {
            return false;
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->getKey())
            ->where('line_role', 'child')
            ->where('parent_epc_id', $this->active_parent_epc_id)
            ->where('status', 'expected')
            ->exists();
    }

    public function isReadyToCompleteInboundAsn(): bool
    {
        if ($this->isScanFirst() || $this->isTransferReceive()) {
            return false;
        }

        if ($this->openToteLockBlocksComplete()) {
            return false;
        }

        $allParentsConfirmed = (int) $this->confirmed_parent_count >= (int) $this->expected_parent_count
            && (int) $this->expected_parent_count > 0;

        if ((int) $this->expected_parent_count === 0) {
            $allParentsConfirmed = true;
        }

        if (! $allParentsConfirmed) {
            return false;
        }

        return ! $this->hasUnclosedExpectedChildrenOfConfirmedParents();
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

    public function canHardDelete(): bool
    {
        return UnsubmittedSessionDelete::canHardDeleteReceiving($this);
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
