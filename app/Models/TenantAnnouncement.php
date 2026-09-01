<?php

namespace App\Models;

use App\Enums\AnnouncementSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantAnnouncement extends Model
{
    protected $fillable = [
        'announcement_id',
        'title',
        'body',
        'severity',
        'published_at',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'severity' => AnnouncementSeverity::class,
            'published_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function dismissals(): HasMany
    {
        return $this->hasMany(TenantAnnouncementDismissal::class);
    }
}
