<?php

namespace App\Models\Receiving;

use App\Models\Epcis\Epc;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundExpectedLine extends Model
{
    protected $table = 'inbound_expected_lines';

    protected $fillable = [
        'inbound_shipment_id',
        'epc_id',
        'parent_epc_id',
        'line_role',
        'status',
        'source',
        'expected_class_qty',
        'confirmed_at',
        'confirmed_by',
        'confirmed_receiving_session_id',
        'claimed_receiving_session_id',
    ];

    protected function casts(): array
    {
        return [
            'expected_class_qty' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function inboundShipment(): BelongsTo
    {
        return $this->belongsTo(InboundShipment::class, 'inbound_shipment_id');
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'epc_id');
    }

    public function parentEpc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'parent_epc_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function confirmedReceivingSession(): BelongsTo
    {
        return $this->belongsTo(ReceivingSession::class, 'confirmed_receiving_session_id');
    }

    public function claimedReceivingSession(): BelongsTo
    {
        return $this->belongsTo(ReceivingSession::class, 'claimed_receiving_session_id');
    }
}
