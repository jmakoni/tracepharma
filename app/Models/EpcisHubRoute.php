<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class EpcisHubRoute extends Model
{
    use CentralConnection;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'provider',
        'gln',
        'sgln_urn',
        'default_inbound_connection_id',
        'is_active',
        'claimed_via',
        'last_routed_at',
    ];

    public const CLAIMED_VIA_ADMIN = 'admin';

    public const CLAIMED_VIA_CONNECTION_AUTO = 'connection_auto';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_routed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdminClaim(): bool
    {
        return $this->claimed_via === self::CLAIMED_VIA_ADMIN;
    }
}
