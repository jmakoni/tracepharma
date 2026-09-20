<?php

namespace App\Models\Disposition;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DispositionSession extends Model
{
    protected $table = 'disposition_sessions';

    protected $fillable = [
        'biz_step',
        'site_id',
        'status',
        'staged_count',
        'confirmed_count',
        'opened_by',
        'opened_at',
        'completed_at',
        'disposition_events_generated_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'disposition_events_generated_at' => 'datetime',
        ];
    }

    public function isExclusive(): bool
    {
        return $this->status === 'open'
            || ($this->status === 'completed' && $this->disposition_events_generated_at === null);
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
        return $this->hasMany(DispositionScanLine::class, 'disposition_session_id');
    }
}
