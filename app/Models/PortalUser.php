<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PortalUser extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'email',
        'name',
        'is_active',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<PortalOrganization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(PortalOrganization::class, 'portal_organization_user')
            ->withPivot('role')
            ->withTimestamps();
    }
}
