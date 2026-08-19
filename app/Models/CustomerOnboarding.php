<?php

namespace App\Models;

use App\Enums\CustomerOnboardingStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOnboarding extends Model
{
    protected $fillable = [
        'status',
        'legal_company_name',
        'company_display_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'contact_role',
        'organization_type',
        'tenant_profile',
        'tenant_type',
        'gln',
        'tenant_slug',
        'owner_name',
        'owner_email',
        'message',
        'demo_request_id',
        'tenant_id',
        'terms_version',
        'privacy_version',
        'terms_accepted_at',
        'privacy_accepted_at',
        'acceptance_ip',
        'acceptance_user_agent',
        'approved_at',
        'approved_by_admin_user_id',
        'provisioned_at',
        'rejected_at',
        'rejection_reason',
        'admin_notes',
        'submission_ip',
        'submission_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerOnboardingStatus::class,
            'tenant_profile' => TenantProfile::class,
            'tenant_type' => TenantType::class,
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'approved_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function demoRequest(): BelongsTo
    {
        return $this->belongsTo(DemoRequest::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_user_id');
    }

    public function reject(string $reason): void
    {
        $tenantId = $this->tenant_id;
        $wasProvisioned = $this->status === CustomerOnboardingStatus::Provisioned;

        $this->update([
            'status' => CustomerOnboardingStatus::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        if (! $wasProvisioned && $tenantId !== null) {
            self::releaseTenant((string) $tenantId);
        }
    }

    public static function releaseTenant(string $tenantId): void
    {
        self::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', CustomerOnboardingStatus::Rejected)
            ->update([
                'tenant_id' => null,
                'status' => CustomerOnboardingStatus::Submitted,
                'provisioned_at' => null,
            ]);

        self::query()
            ->where('tenant_id', $tenantId)
            ->where('status', CustomerOnboardingStatus::Rejected)
            ->update(['tenant_id' => null]);
    }

    public static function clearDeadRejectedClaims(): void
    {
        $deadIds = self::query()
            ->where('status', CustomerOnboardingStatus::Rejected)
            ->whereNotNull('tenant_id')
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->values();

        if ($deadIds->isEmpty()) {
            return;
        }

        $liveIds = Tenant::query()->whereIn('id', $deadIds)->pluck('id');
        $missingIds = $deadIds->diff($liveIds);

        if ($missingIds->isEmpty()) {
            return;
        }

        self::query()
            ->where('status', CustomerOnboardingStatus::Rejected)
            ->whereIn('tenant_id', $missingIds)
            ->update(['tenant_id' => null]);
    }

    public function hasOpenableTenant(): bool
    {
        return $this->tenant?->domains()->exists() === true;
    }

    public function isProvisionable(): bool
    {
        if ($this->status === CustomerOnboardingStatus::Rejected) {
            return false;
        }

        if ($this->status === CustomerOnboardingStatus::Provisioned) {
            return $this->tenant_id !== null && ! Tenant::query()->whereKey($this->tenant_id)->exists();
        }

        if ($this->tenant_id !== null) {
            return true;
        }

        return $this->status?->canApprove() ?? false;
    }
}
