<?php

namespace App\Models\Shipping;

use App\Models\Epcis\Epc;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundShippingScanLine extends Model
{
    protected $table = 'outbound_shipping_scan_lines';

    protected $fillable = [
        'outbound_shipping_session_id',
        'epc_id',
        'line_role',
        'status',
        'scan_raw',
        'confirmed_at',
        'confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(OutboundShippingSession::class, 'outbound_shipping_session_id');
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class);
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
