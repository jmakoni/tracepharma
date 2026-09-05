<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PortalOrganization extends Model
{
    protected $fillable = [
        'trading_partner_id',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TradingPartner, $this>
     */
    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    /**
     * @return BelongsToMany<PortalUser, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(PortalUser::class, 'portal_organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }
}
