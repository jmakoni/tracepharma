<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAccountSecurity;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PortalUser extends Authenticatable
{
    use HasAccountSecurity;
    use Notifiable;

    protected $fillable = [
        'email',
        'name',
        'disabled_reason',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
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
