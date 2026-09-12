<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HubReceiverGlnClaimRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class HubReceiverGlnClaimRequest extends Model
{
    use CentralConnection;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'gln',
        'provider',
        'reason',
        'requested_by',
        'status',
        'reviewed_by_admin_id',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => HubReceiverGlnClaimRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', HubReceiverGlnClaimRequestStatus::Pending);
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
        return $this->getAttribute('status') === HubReceiverGlnClaimRequestStatus::Pending;
    }
}
