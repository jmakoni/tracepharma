<?php

namespace Tests\Feature;

use App\Enums\TenantProfile;
use App\Support\TenantFeatures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TenantFeaturesTest extends TestCase
{
    public function test_null_tenant_defaults_to_pharmacy(): void
    {
        $f = TenantFeatures::forTenant(null);

        $this->assertTrue($f->supportsReceiving());
        $this->assertTrue($f->supportsTransferring());
        $this->assertFalse($f->supportsUnpacking());
        $this->assertTrue($f->supportsPacking());
        $this->assertFalse($f->supportsCommissioning());
        $this->assertTrue($f->supportsReturning());
    }

    public function test_buying_group_has_no_floor_ops(): void
    {
        $f = new TenantFeatures(TenantProfile::BuyingGroup);

        $this->assertFalse($f->supportsReceiving());
        $this->assertFalse($f->supportsTransferring());
        $this->assertFalse($f->supportsUnpacking());
        $this->assertFalse($f->supportsPacking());
        $this->assertFalse($f->supportsCommissioning());
        $this->assertFalse($f->supportsReturning());
        $this->assertFalse($f->hasAnyOperations());
        $this->assertFalse($f->supportsInboundIntegrations());
        $this->assertFalse($f->supportsMasterData());
        $this->assertFalse($f->supportsComplianceCases());
    }

    public function test_pharmacy_supports_compliance_cases(): void
    {
        $f = new TenantFeatures(TenantProfile::Pharmacy);

        $this->assertTrue($f->supportsComplianceCases());
        $this->assertTrue($f->supportsInboundIntegrations());
    }

    public function test_pharmacy_supports_packing_without_outbound_sscc_ship(): void
    {
        $f = new TenantFeatures(TenantProfile::Pharmacy);

        $this->assertTrue($f->supportsPacking());
        $this->assertFalse($f->supportsOutboundIntegrations());
        $this->assertTrue($f->supportsPharmacyOutboundDesk());
        $this->assertTrue($f->canAuthorOutboundShipments());
        $this->assertFalse($f->supportsSsccLabeling());
    }

    public function test_manufacturer_ops(): void
    {
        $f = new TenantFeatures(TenantProfile::Manufacturer);

        $this->assertFalse($f->supportsReceiving());
        $this->assertFalse($f->supportsTransferring());
        $this->assertTrue($f->supportsUnpacking());
        $this->assertTrue($f->supportsPacking());
        $this->assertTrue($f->supportsCommissioning());
        $this->assertTrue($f->supportsReturning());
        $this->assertTrue($f->supportsOutboundIntegrations());
        $this->assertTrue($f->supportsSsccLabeling());
    }

    public function test_pharmacy_does_not_support_sscc_labeling(): void
    {
        $f = new TenantFeatures(TenantProfile::Pharmacy);

        $this->assertFalse($f->supportsOutboundIntegrations());
        $this->assertFalse($f->supportsSsccLabeling());
    }

    #[DataProvider('fullOpsProfiles')]
    public function test_distributor_style_profiles_get_most_ops(TenantProfile $profile): void
    {
        $f = new TenantFeatures($profile);

        $this->assertTrue($f->supportsReceiving());
        $this->assertTrue($f->supportsTransferring());
        $this->assertTrue($f->supportsUnpacking());
        $this->assertTrue($f->supportsPacking());
        $this->assertTrue($f->supportsCommissioning());
        $this->assertTrue($f->supportsReturning());
    }

    /**
     * @return array<string, array{0: TenantProfile}>
     */
    public static function fullOpsProfiles(): array
    {
        return [
            'wholesaler' => [TenantProfile::DrugWholesaler],
            'prepackager' => [TenantProfile::Prepackager],
            '3pl' => [TenantProfile::Logistics3pl],
            'dental' => [TenantProfile::DentalMedicalSupply],
        ];
    }
}
