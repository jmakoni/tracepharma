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
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
