<?php

namespace App\Models\Disposition;

use App\Models\Epcis\Epc;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DispositionScanLine extends Model
{
    protected $table = 'disposition_scan_lines';

    protected $fillable = [
        'disposition_session_id',
        'epc_id',
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
        return $this->belongsTo(DispositionSession::class, 'disposition_session_id');
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'epc_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
