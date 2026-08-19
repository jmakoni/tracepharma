<?php

namespace App\Models\Fda;

use App\Enums\FacilityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FdaWdd3plUnmatched extends FdaModel
{
    protected $table = 'fda_wdd_3pl_unmatched';

    public $timestamps = false;

    protected $fillable = [
        'facility_name',
        'slug_attempt',
        'facility_type',
        'row_count',
        'last_seen_at',
        'resolved_at',
        'fda_organization_id',
    ];

    protected function casts(): array
    {
        return [
            'facility_type' => FacilityType::class,
            'row_count' => 'integer',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function fdaOrganization(): BelongsTo
    {
        return $this->belongsTo(FdaOrganization::class);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }
}
