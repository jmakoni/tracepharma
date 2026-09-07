<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionApprovalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class ConnectionApprovalRequest extends Model
{
    use CentralConnection;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'direction',
        'connection_id',
        'connection_name',
        'provider',
        'transport',
        'counterparty',
        'endpoint_host',
        'requested_by',
        'status',
        'reviewed_by_admin_id',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConnectionApprovalStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ConnectionApprovalStatus::Pending);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }

    public function isPending(): bool
    {
        return $this->status === ConnectionApprovalStatus::Pending;
    }
}
