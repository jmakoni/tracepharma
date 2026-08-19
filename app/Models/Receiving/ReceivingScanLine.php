<?php

namespace App\Models\Receiving;

use App\Models\Epcis\Epc;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivingScanLine extends Model
{
    protected $table = 'receiving_scan_lines';

    protected $fillable = [
        'receiving_session_id',
        'epc_id',
        'parent_epc_id',
        'line_role',
        'status',
        'scan_raw',
        'confirmed_at',
        'confirmed_by',
        'ilmd_mismatch_json',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'ilmd_mismatch_json' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReceivingSession::class, 'receiving_session_id');
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
}
