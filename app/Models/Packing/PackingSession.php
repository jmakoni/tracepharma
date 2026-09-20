<?php

namespace App\Models\Packing;

use App\Enums\PackingSessionKind;
use App\Models\Epcis\Epc;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackingSession extends Model
{
    protected $table = 'packing_sessions';

    protected $fillable = [
        'session_kind',
        'site_id',
        'status',
        'parent_epc_id',
        'parent_label_id',
        'parent_sscc18',
        'staged_count',
        'confirmed_count',
        'opened_by',
        'opened_at',
        'completed_at',
        'packing_events_generated_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'session_kind' => PackingSessionKind::class,
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'packing_events_generated_at' => 'datetime',
        ];
    }

    public function isExclusive(): bool
    {
        return $this->status === 'open'
            || ($this->status === 'completed' && $this->packing_events_generated_at === null);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function parentEpc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'parent_epc_id');
    }

    public function parentLabel(): BelongsTo
    {
        return $this->belongsTo(SsccLabel::class, 'parent_label_id');
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function scanLines(): HasMany
    {
        return $this->hasMany(PackingScanLine::class, 'packing_session_id');
    }
}
