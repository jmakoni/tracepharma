<?php

namespace App\Support\CustomerOnboarding;

use App\Enums\TenantProfile;
use App\Enums\TenantType;

class OrganizationTypeMapper
{
    /**
     * @return array{profile: TenantProfile, type: TenantType}
     */
    public static function map(string $organizationType): array
    {
        $profile = match ($organizationType) {
            'manufacturer' => TenantProfile::Manufacturer,
            'wholesaler' => TenantProfile::DrugWholesaler,
            'logistics_3pl' => TenantProfile::Logistics3pl,
            'buying_group' => TenantProfile::BuyingGroup,
            'dental_medical' => TenantProfile::DentalMedicalSupply,
            'prepackager' => TenantProfile::Prepackager,
            default => TenantProfile::Pharmacy,
        };

        return [
            'profile' => $profile,
            'type' => $profile->tenantType(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'independent_pharmacy' => 'Independent pharmacy',
            'hospital_health_system' => 'Hospital / health system',
            'wholesaler' => 'Drug wholesaler',
            'manufacturer' => 'Drug manufacturer',
            'logistics_3pl' => '3PL / logistics',
            'buying_group' => 'Pharmacy buying group',
            'dental_medical' => 'Dental / medical supply',
            'prepackager' => 'Prepackager / repackager',
            'other' => 'Other dispenser or distributor',
        ];
    }
}
