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
        $this->assertTrue($f->supportsDispositionDecommission());
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
        $this->assertFalse($f->supportsDispositionDecommission());
        $this->assertFalse($f->supportsReturning());
        $this->assertFalse($f->hasAnyOperations());
        $this->assertFalse($f->supportsInboundIntegrations());
        $this->assertFalse($f->supportsMasterData());
        $this->assertFalse($f->supportsComplianceCases());
        $this->assertTrue($f->supportsPartnerReadiness());
        $this->assertTrue($f->supportsComplianceAlertCenter());
        $this->assertTrue($f->supportsBuyingGroupNetwork());
    }

    public function test_pharmacy_supports_compliance_cases(): void
    {
        $f = new TenantFeatures(TenantProfile::Pharmacy);

        $this->assertTrue($f->supportsComplianceCases());
        $this->assertTrue($f->supportsInboundIntegrations());
        $this->assertFalse($f->supportsBuyingGroupNetwork());
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

        $this->assertTrue($f->supportsReceiving());
        $this->assertFalse($f->supportsVrs());
        $this->assertTrue($f->supportsVrsResponder());
        $this->assertTrue($f->supportsTransferring());
        $this->assertTrue($f->supportsUnpacking());
        $this->assertTrue($f->supportsPacking());
        $this->assertTrue($f->supportsCommissioning());
        $this->assertTrue($f->supportsDispositionDecommission());
        $this->assertTrue($f->supportsReturning());
        $this->assertTrue($f->supportsOutboundIntegrations());
        $this->assertFalse($f->supportsPharmacyOutboundDesk());
        $this->assertTrue($f->supportsSsccLabeling());
    }

    public function test_pharmacy_does_not_support_sscc_labeling(): void
    {
        $f = new TenantFeatures(TenantProfile::Pharmacy);

        $this->assertFalse($f->supportsOutboundIntegrations());
        $this->assertFalse($f->supportsSsccLabeling());
    }

    public function test_logistics_3pl_supports_principals(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::Logistics3pl))->supportsPrincipals());
        $this->assertFalse((new TenantFeatures(TenantProfile::DrugWholesaler))->supportsPrincipals());
        $this->assertFalse((new TenantFeatures(TenantProfile::Pharmacy))->supportsPrincipals());
    }

    public function test_only_prepackager_supports_repack_transform(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::Prepackager))->supportsRepackTransform());
        $this->assertFalse((new TenantFeatures(TenantProfile::Pharmacy))->supportsRepackTransform());
        $this->assertFalse((new TenantFeatures(TenantProfile::DrugWholesaler))->supportsRepackTransform());
        $this->assertFalse((new TenantFeatures(TenantProfile::Manufacturer))->supportsRepackTransform());
    }

    #[DataProvider('fullOpsProfiles')]
    public function test_distributor_style_profiles_get_most_ops(TenantProfile $profile): void
    {
        $f = new TenantFeatures($profile);

        $this->assertTrue($f->supportsReceiving());
        $this->assertTrue($f->supportsTransferring());
        $this->assertTrue($f->supportsUnpacking());
        $this->assertTrue($f->supportsPacking());
        $this->assertTrue($f->supportsReturning());
    }

    public function test_commissioning_only_for_manufacturer_and_prepackager(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::Manufacturer))->supportsCommissioning());
        $this->assertTrue((new TenantFeatures(TenantProfile::Prepackager))->supportsCommissioning());
        $this->assertFalse((new TenantFeatures(TenantProfile::DrugWholesaler))->supportsCommissioning());
        $this->assertFalse((new TenantFeatures(TenantProfile::Logistics3pl))->supportsCommissioning());
        $this->assertFalse((new TenantFeatures(TenantProfile::DentalMedicalSupply))->supportsCommissioning());
        $this->assertFalse((new TenantFeatures(TenantProfile::Pharmacy))->supportsCommissioning());
        $this->assertFalse((new TenantFeatures(TenantProfile::BuyingGroup))->supportsCommissioning());
    }

    public function test_disposition_decommission_for_ops_profiles_not_buying_group(): void
    {
        $this->assertTrue((new TenantFeatures(TenantProfile::Pharmacy))->supportsDispositionDecommission());
        $this->assertTrue((new TenantFeatures(TenantProfile::Manufacturer))->supportsDispositionDecommission());
        $this->assertTrue((new TenantFeatures(TenantProfile::DrugWholesaler))->supportsDispositionDecommission());
        $this->assertTrue((new TenantFeatures(TenantProfile::Prepackager))->supportsDispositionDecommission());
        $this->assertTrue((new TenantFeatures(TenantProfile::Logistics3pl))->supportsDispositionDecommission());
        $this->assertTrue((new TenantFeatures(TenantProfile::DentalMedicalSupply))->supportsDispositionDecommission());
        $this->assertFalse((new TenantFeatures(TenantProfile::BuyingGroup))->supportsDispositionDecommission());
    }

    /**
     * Dental = wholesaler-lite (distributor floor + SSCC ship path; no plant commission; not pharmacy desk).
     */
    public function test_dental_is_wholesaler_lite(): void
    {
        $f = new TenantFeatures(TenantProfile::DentalMedicalSupply);

        $this->assertTrue($f->supportsReceiving());
        $this->assertTrue($f->supportsVrs());
        $this->assertTrue($f->supportsTransferring());
        $this->assertTrue($f->supportsUnpacking());
        $this->assertTrue($f->supportsPacking());
        $this->assertTrue($f->supportsReturning());
        $this->assertFalse($f->supportsCommissioning());
        $this->assertTrue($f->supportsDispositionDecommission());
        $this->assertTrue($f->supportsOutboundIntegrations());
        $this->assertTrue($f->supportsSsccLabeling());
        $this->assertFalse($f->supportsPharmacyOutboundDesk());

        $fullWholesalerFamily = new \ReflectionMethod(TenantFeatures::class, 'isFullWholesalerFamilyProfile');
        $this->assertFalse($fullWholesalerFamily->invoke($f));
        $this->assertTrue($fullWholesalerFamily->invoke(new TenantFeatures(TenantProfile::DrugWholesaler)));
        $this->assertFalse($fullWholesalerFamily->invoke(new TenantFeatures(TenantProfile::Pharmacy)));
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
