<?php

namespace App\Models;

use App\Enums\AnnouncementFanOutStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class AnnouncementTenant extends Pivot
{
    use CentralConnection;

    protected $table = 'announcement_tenant';

    public $incrementing = true;

    protected $fillable = [
        'announcement_id',
        'tenant_id',
        'fan_out_status',
        'fan_out_error',
        'fan_out_at',
    ];

    protected function casts(): array
    {
        return [
            'fan_out_status' => AnnouncementFanOutStatus::class,
            'fan_out_at' => 'datetime',
        ];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
