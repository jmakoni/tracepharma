<?php

namespace App\Models\Transferring;

use App\Models\Epcis\Epc;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferringScanLine extends Model
{
    protected $table = 'transferring_scan_lines';

    protected $fillable = [
        'transferring_session_id',
        'epc_id',
        'status',
        'scan_raw',
        'confirmed_at',
        'confirmed_by',
        'received_at',
        'received_by',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TransferringSession::class, 'transferring_session_id');
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'epc_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
