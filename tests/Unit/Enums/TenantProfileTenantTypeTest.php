<?php

namespace Tests\Unit\Enums;

use App\Enums\TenantProfile;
use App\Enums\TenantType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantProfileTenantTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('profileToTypeProvider')]
    public function profile_maps_to_expected_tenant_type(TenantProfile $profile, TenantType $expected): void
    {
        $this->assertSame($expected, $profile->tenantType());
    }

    /**
     * @return array<string, array{0: TenantProfile, 1: TenantType}>
     */
    public static function profileToTypeProvider(): array
    {
        return [
            'pharmacy' => [TenantProfile::Pharmacy, TenantType::Pharmacy],
            'logistics_3pl' => [TenantProfile::Logistics3pl, TenantType::ThreePl],
            'manufacturer' => [TenantProfile::Manufacturer, TenantType::Distributor],
            'drug_wholesaler' => [TenantProfile::DrugWholesaler, TenantType::Distributor],
            'prepackager' => [TenantProfile::Prepackager, TenantType::Distributor],
            'dental_medical_supply' => [TenantProfile::DentalMedicalSupply, TenantType::Distributor],
            'buying_group' => [TenantProfile::BuyingGroup, TenantType::Distributor],
        ];
    }

    #[Test]
    public function tenant_type_labels_are_stable(): void
    {
        $this->assertSame('Pharmacy', TenantType::Pharmacy->label());
        $this->assertSame('Distributor', TenantType::Distributor->label());
        $this->assertSame('3PL / Logistics', TenantType::ThreePl->label());
    }
}
