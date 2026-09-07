<?php

namespace App\Models;

use App\Enums\TenantProfile;
use App\Support\TenantFeatures;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'created_at',
            'updated_at',
            'name',
            'profile',
            'status',
            'gln',
            'company_prefix',
            'inbound_environment',
            'hub_providers',
        ];
    }

    protected $fillable = [
        'id',
        'name',
        'profile',
        'status',
        'suspension_reason',
        'suspended_at',
        'suspended_by',
        'gln',
        'company_prefix',
        'inbound_environment',
        'hub_providers',
        'tenancy_db_name',
        'receiving_state',
        'tenant_pair_slug',
        'tenant_pair_environment',
    ];

    protected function casts(): array
    {
        return [
            'profile' => TenantProfile::class,
            'status' => 'string',
            'hub_providers' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    public function features(): TenantFeatures
    {
        return TenantFeatures::forTenant($this);
    }

    public function platformAuditEvents(): HasMany
    {
        return $this->hasMany(PlatformAuditEvent::class, 'tenant_id');
    }

    public function hubRoutes(): HasMany
    {
        return $this->hasMany(EpcisHubRoute::class, 'tenant_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (Tenant $tenant): void {
            CustomerOnboarding::releaseTenant((string) $tenant->id);
        });
    }
}
