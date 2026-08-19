<?php

namespace App\Actions\CustomerOnboarding;

use App\Actions\Tenants\ProvisionTenantOnEnvironment;
use App\Actions\Tenants\ProvisionTenantPair;
use App\Enums\CustomerOnboardingStatus;
use App\Models\CustomerOnboarding;
use App\Models\Tenant;
use App\Support\CustomerOnboarding\OrganizationTypeMapper;
use App\Support\TenantHostname;
use App\Support\TenantPairAvailability;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ApproveAndProvisionCustomerOnboarding
{
    public function __construct(
        private readonly ProvisionTenantPair $pair,
        private readonly ProvisionTenantOnEnvironment $onEnvironment,
    ) {}

    /**
     * @param  array{tenant_slug: string, gln?: ?string, owner_name?: ?string, owner_email?: ?string, owner_password: string, admin_notes?: ?string}  $data
     */
    public function execute(CustomerOnboarding $onboarding, array $data, int $adminUserId): Tenant
    {
        if (! $onboarding->isProvisionable()) {
            throw new RuntimeException('Only submitted or in-progress onboarding requests can be provisioned.');
        }

        $slug = strtolower((string) $data['tenant_slug']);
        TenantHostname::assertProvisionableSlug($slug);

        TenantPairAvailability::assertSlugAllowedFor($onboarding, $slug);

        $mapped = OrganizationTypeMapper::map($onboarding->organization_type);

        $onboarding->update([
            'tenant_slug' => $slug,
            'gln' => $data['gln'] ?? $onboarding->gln,
            'tenant_profile' => $mapped['profile'],
            'tenant_type' => $mapped['type'],
            'owner_name' => $data['owner_name'] ?? $onboarding->contact_name,
            'owner_email' => $data['owner_email'] ?? $onboarding->contact_email,
            'admin_notes' => $data['admin_notes'] ?? $onboarding->admin_notes,
        ]);

        $attributes = [
            'name' => $onboarding->company_display_name,
            'profile' => $mapped['profile'],
            'status' => 'active',
            'gln' => $data['gln'] ?? $onboarding->gln,
        ];

        $owner = [
            'name' => (string) ($data['owner_name'] ?? $onboarding->contact_name),
            'email' => (string) ($data['owner_email'] ?? $onboarding->contact_email),
            'password' => (string) $data['owner_password'],
        ];

        try {
            $prod = $this->pair->create(
                $slug,
                $attributes,
                [],
                $owner,
                TenantPairAvailability::resumeTenantIdFor($onboarding, $slug),
            );

            $onboarding->update(['tenant_id' => $prod->id]);

            $now = now();
            $onboarding->update([
                'status' => CustomerOnboardingStatus::Provisioned,
                'approved_by_admin_user_id' => $adminUserId,
                'approved_at' => $onboarding->approved_at ?? $now,
                'provisioned_at' => $now,
            ]);
        } catch (Throwable $exception) {
            if ($exception instanceof UniqueConstraintViolationException
                && $this->isOnboardingTenantIdUnique($exception)) {
                throw new RuntimeException(
                    'This tenant is already linked to another onboarding request.',
                    0,
                    $exception,
                );
            }

            $partial = $this->onEnvironment->findBySlugAndEnvironment($slug, 'prod');

            if ($partial instanceof Tenant) {
                $onboarding->update(['tenant_id' => $partial->id]);
            }

            throw $exception;
        }

        return $prod->load('domains');
    }

    public static function suggestSlug(string $companyDisplayName): string
    {
        $base = Str::slug($companyDisplayName);
        $base = $base !== '' ? $base : 'customer';
        $base = strtolower($base);

        if (preg_match(TenantHostname::dnsSlugPattern(), $base) !== 1 || TenantHostname::isReservedSlug($base)) {
            $base = 'customer';
        }

        if (! self::pairTaken($base) && ! TenantHostname::isReservedSlug($base)) {
            return $base;
        }

        do {
            $candidate = $base.'-'.Str::lower(Str::random(4));
        } while (TenantHostname::isReservedSlug($candidate) || self::pairTaken($candidate));

        return $candidate;
    }

    private function isOnboardingTenantIdUnique(UniqueConstraintViolationException $exception): bool
    {
        if ($exception->index === 'customer_onboardings_tenant_id_unique') {
            return true;
        }

        return str_contains($exception->getMessage(), 'customer_onboardings_tenant_id_unique');
    }

    private static function pairTaken(string $slug): bool
    {
        if (TenantPairAvailability::validationMessage($slug) === null) {
            return false;
        }

        $probe = new CustomerOnboarding;
        $probe->tenant_id = null;

        return TenantPairAvailability::resumeTenantIdFor($probe, $slug) === null;
    }
}
