<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAnnouncementDismissal extends Model
{
    protected $fillable = [
        'tenant_announcement_id',
        'user_id',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }

    public function tenantAnnouncement(): BelongsTo
    {
        return $this->belongsTo(TenantAnnouncement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
