<?php

namespace Tests\Unit;

use App\Enums\TenantProfile;
use App\Support\TenantFeatures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProfileNavigationMatrixTest extends TestCase
{
    /**
     * @return iterable<string, array{0: TenantProfile, 1: string, 2: bool}>
     */
    public static function matrixExpectations(): iterable
    {
        $methods = [
            'supportsReceiving',
            'supportsVrs',
            'supportsTransferring',
            'supportsUnpacking',
            'supportsPacking',
            'supportsCommissioning',
            'supportsReturning',
            'supportsMasterData',
            'supportsPrincipals',
            'supportsRepackTransform',
            'supportsInboundIntegrations',
            'supportsOutboundIntegrations',
            'supportsPharmacyOutboundDesk',
            'supportsSsccLabeling',
            'supportsTracingRequests',
            'supportsPartnerReadiness',
            'supportsComplianceAlertCenter',
            'supportsComplianceReports',
            'supportsComplianceCases',
            'supportsBuyingGroupNetwork',
        ];

        $matrix = [
            TenantProfile::Pharmacy->value => [
                'supportsReceiving' => true,
                'supportsVrs' => true,
                'supportsTransferring' => true,
                'supportsUnpacking' => false,
                'supportsPacking' => true,
                'supportsCommissioning' => false,
                'supportsReturning' => true,
                'supportsMasterData' => true,
                'supportsPrincipals' => false,
                'supportsRepackTransform' => false,
                'supportsInboundIntegrations' => true,
                'supportsOutboundIntegrations' => false,
                'supportsPharmacyOutboundDesk' => true,
                'supportsSsccLabeling' => false,
                'supportsTracingRequests' => true,
                'supportsPartnerReadiness' => true,
                'supportsComplianceAlertCenter' => true,
                'supportsComplianceReports' => true,
                'supportsComplianceCases' => true,
                'supportsBuyingGroupNetwork' => false,
            ],
            TenantProfile::Manufacturer->value => [
                'supportsReceiving' => false,
                'supportsVrs' => false,
                'supportsTransferring' => false,
                'supportsUnpacking' => true,
                'supportsPacking' => true,
                'supportsCommissioning' => true,
                'supportsReturning' => true,
                'supportsMasterData' => true,
                'supportsPrincipals' => false,
                'supportsRepackTransform' => false,
                'supportsInboundIntegrations' => true,
                'supportsOutboundIntegrations' => true,
                'supportsPharmacyOutboundDesk' => false,
                'supportsSsccLabeling' => true,
                'supportsTracingRequests' => true,
                'supportsPartnerReadiness' => true,
                'supportsComplianceAlertCenter' => true,
                'supportsComplianceReports' => true,
                'supportsComplianceCases' => true,
                'supportsBuyingGroupNetwork' => false,
            ],
            TenantProfile::DrugWholesaler->value => [
                'supportsReceiving' => true,
                'supportsVrs' => true,
                'supportsTransferring' => true,
                'supportsUnpacking' => true,
                'supportsPacking' => true,
                'supportsCommissioning' => true,
                'supportsReturning' => true,
                'supportsMasterData' => true,
                'supportsPrincipals' => false,
                'supportsRepackTransform' => false,
                'supportsInboundIntegrations' => true,
                'supportsOutboundIntegrations' => true,
                'supportsPharmacyOutboundDesk' => false,
                'supportsSsccLabeling' => true,
                'supportsTracingRequests' => true,
                'supportsPartnerReadiness' => true,
                'supportsComplianceAlertCenter' => true,
                'supportsComplianceReports' => true,
                'supportsComplianceCases' => true,
                'supportsBuyingGroupNetwork' => false,
            ],
            TenantProfile::Prepackager->value => [
                'supportsReceiving' => true,
                'supportsVrs' => true,
                'supportsTransferring' => true,
                'supportsUnpacking' => true,
                'supportsPacking' => true,
                'supportsCommissioning' => true,
                'supportsReturning' => true,
                'supportsMasterData' => true,
                'supportsPrincipals' => false,
                'supportsRepackTransform' => true,
                'supportsInboundIntegrations' => true,
                'supportsOutboundIntegrations' => true,
                'supportsPharmacyOutboundDesk' => false,
                'supportsSsccLabeling' => true,
                'supportsTracingRequests' => true,
                'supportsPartnerReadiness' => true,
                'supportsComplianceAlertCenter' => true,
                'supportsComplianceReports' => true,
                'supportsComplianceCases' => true,
                'supportsBuyingGroupNetwork' => false,
            ],
            TenantProfile::Logistics3pl->value => [
                'supportsReceiving' => true,
                'supportsVrs' => true,
                'supportsTransferring' => true,
                'supportsUnpacking' => true,
                'supportsPacking' => true,
                'supportsCommissioning' => true,
                'supportsReturning' => true,
                'supportsMasterData' => true,
                'supportsPrincipals' => true,
                'supportsRepackTransform' => false,
                'supportsInboundIntegrations' => true,
                'supportsOutboundIntegrations' => true,
                'supportsPharmacyOutboundDesk' => false,
                'supportsSsccLabeling' => true,
                'supportsTracingRequests' => true,
                'supportsPartnerReadiness' => true,
                'supportsComplianceAlertCenter' => true,
                'supportsComplianceReports' => true,
                'supportsComplianceCases' => true,
                'supportsBuyingGroupNetwork' => false,
            ],
            TenantProfile::DentalMedicalSupply->value => [
                'supportsReceiving' => true,
                'supportsVrs' => true,
                'supportsTransferring' => true,
                'supportsUnpacking' => true,
                'supportsPacking' => true,
                'supportsCommissioning' => true,
                'supportsReturning' => true,
                'supportsMasterData' => true,
                'supportsPrincipals' => false,
                'supportsRepackTransform' => false,
                'supportsInboundIntegrations' => true,
                'supportsOutboundIntegrations' => true,
                'supportsPharmacyOutboundDesk' => false,
                'supportsSsccLabeling' => true,
                'supportsTracingRequests' => true,
                'supportsPartnerReadiness' => true,
                'supportsComplianceAlertCenter' => true,
                'supportsComplianceReports' => true,
                'supportsComplianceCases' => true,
                'supportsBuyingGroupNetwork' => false,
            ],
            TenantProfile::BuyingGroup->value => [
                'supportsReceiving' => false,
                'supportsVrs' => false,
                'supportsTransferring' => false,
                'supportsUnpacking' => false,
                'supportsPacking' => false,
                'supportsCommissioning' => false,
                'supportsReturning' => false,
                'supportsMasterData' => false,
                'supportsPrincipals' => false,
                'supportsRepackTransform' => false,
                'supportsInboundIntegrations' => false,
                'supportsOutboundIntegrations' => false,
                'supportsPharmacyOutboundDesk' => false,
                'supportsSsccLabeling' => false,
                'supportsTracingRequests' => false,
                'supportsPartnerReadiness' => true,
                'supportsComplianceAlertCenter' => true,
                'supportsComplianceReports' => false,
                'supportsComplianceCases' => false,
                'supportsBuyingGroupNetwork' => true,
            ],
        ];

        foreach ($matrix as $profileValue => $expectations) {
            $profile = TenantProfile::from($profileValue);

            foreach ($methods as $method) {
                yield "{$profileValue}:{$method}" => [
                    $profile,
                    $method,
                    $expectations[$method],
                ];
            }
        }
    }

    #[DataProvider('matrixExpectations')]
    public function test_profile_capability_matrix(
        TenantProfile $profile,
        string $method,
        bool $expected,
    ): void {
        $features = new TenantFeatures($profile);

        $this->assertSame($expected, $features->{$method}());
    }

    public function test_buying_group_control_plane_only(): void
    {
        $features = new TenantFeatures(TenantProfile::BuyingGroup);

        $this->assertFalse($features->supportsReceiving());
        $this->assertFalse($features->supportsTransferring());
        $this->assertFalse($features->supportsUnpacking());
        $this->assertFalse($features->supportsPacking());
        $this->assertFalse($features->supportsCommissioning());
        $this->assertFalse($features->supportsReturning());
        $this->assertFalse($features->hasAnyOperations());

        $this->assertFalse($features->supportsMasterData());
        $this->assertFalse($features->supportsInboundIntegrations());
        $this->assertFalse($features->supportsComplianceCases());

        $this->assertTrue($features->supportsPartnerReadiness());
        $this->assertTrue($features->supportsComplianceAlertCenter());
        $this->assertTrue($features->supportsBuyingGroupNetwork());
    }
}
