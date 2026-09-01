<?php

namespace App\Models;

use App\Enums\AnnouncementSeverity;
use App\Enums\AnnouncementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Announcement extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'body',
        'severity',
        'status',
        'starts_at',
        'ends_at',
        'published_at',
        'retired_at',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'severity' => AnnouncementSeverity::class,
            'status' => AnnouncementStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Announcement $announcement): void {
            if (blank($announcement->id)) {
                $announcement->id = (string) Str::uuid();
            }
        });
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'announcement_tenant')
            ->using(AnnouncementTenant::class)
            ->withPivot(['fan_out_status', 'fan_out_error', 'fan_out_at'])
            ->withTimestamps();
    }
}
