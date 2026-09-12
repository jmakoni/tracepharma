<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BuyingGroupMembershipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class BuyingGroupMembership extends Model
{
    use CentralConnection;

    public const CONSENT_VERSION = '1';

    /** @var list<string> */
    protected $fillable = [
        'buying_group_tenant_id',
        'member_tenant_id',
        'status',
        'bg_member_local_id',
        'consent_version',
        'invited_by',
        'invited_at',
        'accepted_by',
        'accepted_at',
        'revoked_by',
        'revoked_at',
        'revoke_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => BuyingGroupMembershipStatus::class,
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'bg_member_local_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', BuyingGroupMembershipStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BuyingGroupMembershipStatus::Active);
    }

    public function buyingGroupTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'buying_group_tenant_id');
    }

    public function memberTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'member_tenant_id');
    }

    public function isPending(): bool
    {
        return $this->status === BuyingGroupMembershipStatus::Pending;
    }

    public function isActive(): bool
    {
        return $this->status === BuyingGroupMembershipStatus::Active;
    }
}
