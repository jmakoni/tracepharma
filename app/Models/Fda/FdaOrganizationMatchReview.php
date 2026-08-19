<?php

namespace App\Models\Fda;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FdaOrganizationMatchReview extends FdaModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_LINKED = 'linked';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CREATED_NEW = 'created_new';

    protected $fillable = [
        'source',
        'original_name',
        'canonical_name',
        'duns_number',
        'proposed_fda_organization_id',
        'resolved_fda_organization_id',
        'resolved_by_admin_id',
        'resolved_at',
        'confidence',
        'status',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'payload_json' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function proposedOrganization(): BelongsTo
    {
        return $this->belongsTo(FdaOrganization::class, 'proposed_fda_organization_id');
    }

    public function resolvedOrganization(): BelongsTo
    {
        return $this->belongsTo(FdaOrganization::class, 'resolved_fda_organization_id');
    }

    public function resolvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by_admin_id');
    }
}
