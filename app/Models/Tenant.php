<?php

namespace App\Models;

use App\Enums\CustomerOnboardingStatus;
use App\Enums\TenantProfile;
use App\Support\TenantFeatures;
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
        ];
    }

    public function features(): TenantFeatures
    {
        return TenantFeatures::forTenant($this);
    }

    protected static function booted(): void
    {
        static::deleting(function (Tenant $tenant): void {
            CustomerOnboarding::releaseTenant((string) $tenant->id);
        });
    }
}
