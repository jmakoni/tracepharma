<?php

namespace Tests\Unit\Support\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ReceivingScanLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReceivingPolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('profileMatrix')]
    public function policy_matches_expected_matrix(
        TenantProfile $profile,
        ReceivingScanLevel $expectedScanLevel,
        bool $expectedAutoConfirmChildren,
        bool $expectedCanUnpackAtReceive,
        bool $expectedCanUnpackAfterReceive,
    ): void {
        $policy = ReceivingPolicy::forProfile($profile);

        $this->assertSame($expectedScanLevel, $policy->preferredScanLevel());
        $this->assertSame($expectedAutoConfirmChildren, $policy->defaultAutoConfirmChildren());
        $this->assertSame($expectedCanUnpackAtReceive, $policy->canUnpackAtReceive());
        $this->assertSame($expectedCanUnpackAfterReceive, $policy->canUnpackAfterReceive());
    }

    /**
     * @return array<string, array{0: TenantProfile, 1: ReceivingScanLevel, 2: bool, 3: bool, 4: bool}>
     */
    public static function profileMatrix(): array
    {
        return [
            'pharmacy' => [TenantProfile::Pharmacy, ReceivingScanLevel::ToteOrCase, true, true, false],
            'manufacturer' => [TenantProfile::Manufacturer, ReceivingScanLevel::Pallet, false, false, true],
            'drug_wholesaler' => [TenantProfile::DrugWholesaler, ReceivingScanLevel::Pallet, true, false, true],
            'prepackager' => [TenantProfile::Prepackager, ReceivingScanLevel::Pallet, true, false, true],
            'logistics_3pl' => [TenantProfile::Logistics3pl, ReceivingScanLevel::Pallet, true, false, true],
            'dental_medical_supply' => [TenantProfile::DentalMedicalSupply, ReceivingScanLevel::Pallet, true, false, true],
            'buying_group' => [TenantProfile::BuyingGroup, ReceivingScanLevel::Pallet, false, false, false],
        ];
    }

    #[Test]
    public function null_tenant_defaults_to_pharmacy_policy(): void
    {
        $policy = ReceivingPolicy::forTenant(null);

        $this->assertSame(ReceivingScanLevel::ToteOrCase, $policy->preferredScanLevel());
        $this->assertTrue($policy->defaultAutoConfirmChildren());
        $this->assertTrue($policy->canUnpackAtReceive());
    }

    #[Test]
    public function sealed_parent_policy_is_sscc_only_for_operator_scans(): void
    {
        $this->assertTrue(
            (new ReceivingPolicy(TenantProfile::DrugWholesaler, ReceivingEdgeMode::SealedParent))
                ->operatorScansSsccOnly(),
        );
        $this->assertFalse(
            (new ReceivingPolicy(TenantProfile::DrugWholesaler, ReceivingEdgeMode::OpenCount))
                ->operatorScansSsccOnly(),
        );
        $this->assertFalse(
            (new ReceivingPolicy(TenantProfile::Pharmacy, ReceivingEdgeMode::ToteLpn))
                ->operatorScansSsccOnly(),
        );
        $this->assertStringContainsString(
            'do not scan cases',
            (new ReceivingPolicy(TenantProfile::DrugWholesaler, ReceivingEdgeMode::SealedParent))
                ->promptCopy()['scanHelper'],
        );
    }

    #[Test]
    public function pharmacy_prompt_copy_uses_sscc_or_case_placeholder(): void
    {
        $copy = ReceivingPolicy::forProfile(TenantProfile::Pharmacy)->promptCopy();

        $this->assertSame('Sealed tote — receive policy. Scan SSCC or Case barcode', $copy['scanHelper']);
        $this->assertStringStartsWith('Sealed tote — receive policy. ', $copy['kindHelper']);
        $this->assertStringContainsString('tote/case', strtolower($copy['sealedPalletLabel']));
        $this->assertSame('Applies to the next tote/case scan.', $copy['sealedPalletHelper']);
        $this->assertSame('Confirm tote/case + units', $copy['confirmLabelSealed']);
        $this->assertSame('Confirm', $copy['confirmLabel']);
    }

    #[Test]
    public function kind_hud_copy_uses_receive_button_for_asn_and_transfer(): void
    {
        $policy = ReceivingPolicy::forProfile(TenantProfile::DrugWholesaler);

        $this->assertSame('ADD', $policy->kindHudCopy(ReceivingSessionKind::ScanFirst)['confirmButton']);
        $this->assertSame('RECEIVE', $policy->kindHudCopy(ReceivingSessionKind::InboundAsn)['confirmButton']);
        $this->assertSame('RECEIVE', $policy->kindHudCopy(ReceivingSessionKind::TransferReceive)['confirmButton']);
    }

    #[Test]
    public function edge_mode_chip_labels_describe_receive_policy_not_lspedia_edge(): void
    {
        $this->assertSame('Sealed parent — receive policy', ReceivingEdgeMode::SealedParent->chipLabel());
        $this->assertSame('Sealed tote — receive policy', ReceivingEdgeMode::ToteLpn->chipLabel());
        $this->assertSame('Case only — receive policy', ReceivingEdgeMode::CaseOnly->chipLabel());
        $this->assertSame('Open count — receive policy', ReceivingEdgeMode::OpenCount->chipLabel());
        $this->assertSame('Open tote — receive policy', ReceivingEdgeMode::OpenTote->chipLabel());
        $this->assertSame('Units only — receive policy', ReceivingEdgeMode::UnitsOnly->chipLabel());
    }

    #[Test]
    #[DataProvider('edgeModeMatrix')]
    public function edge_mode_overrides_preferred_level_and_auto_confirm(
        ReceivingEdgeMode $mode,
        ReceivingScanLevel $expectedScanLevel,
        bool $expectedAutoConfirmChildren,
        bool $expectedOperatorScansSsccOnly,
    ): void {
        $policy = new ReceivingPolicy(TenantProfile::DrugWholesaler, $mode);

        $this->assertSame($mode, $policy->edgeMode());
        $this->assertSame($expectedScanLevel, $policy->preferredScanLevel());
        $this->assertSame($expectedAutoConfirmChildren, $policy->defaultAutoConfirmChildren());
        $this->assertSame($expectedOperatorScansSsccOnly, $policy->operatorScansSsccOnly());
        $this->assertSame($mode === ReceivingEdgeMode::CaseOnly, $policy->operatorScansCaseOnly());
        $this->assertSame($mode === ReceivingEdgeMode::UnitsOnly, $policy->operatorScansUnitsOnly());
    }

    /**
     * @return array<string, array{0: ReceivingEdgeMode, 1: ReceivingScanLevel, 2: bool, 3: bool}>
     */
    public static function edgeModeMatrix(): array
    {
        return [
            'sealed_parent' => [ReceivingEdgeMode::SealedParent, ReceivingScanLevel::Pallet, true, true],
            'tote_lpn' => [ReceivingEdgeMode::ToteLpn, ReceivingScanLevel::ToteOrCase, true, false],
            'case_only' => [ReceivingEdgeMode::CaseOnly, ReceivingScanLevel::Case, true, false],
            'open_count' => [ReceivingEdgeMode::OpenCount, ReceivingScanLevel::Pallet, false, false],
            'open_tote' => [ReceivingEdgeMode::OpenTote, ReceivingScanLevel::Pallet, false, false],
            'units_only' => [ReceivingEdgeMode::UnitsOnly, ReceivingScanLevel::Case, false, false],
        ];
    }

    #[Test]
    public function prompt_copy_names_explicit_open_tote_sop(): void
    {
        $copy = (new ReceivingPolicy(TenantProfile::Pharmacy, ReceivingEdgeMode::OpenTote))->promptCopy();

        $this->assertSame('Open tote — receive policy. Scan SSCC or Case barcode', $copy['scanHelper']);
        $this->assertStringStartsWith('Open tote — receive policy. ', $copy['kindHelper']);
    }

    #[Test]
    public function case_only_prompt_copy_tells_operator_to_scan_the_case(): void
    {
        $copy = (new ReceivingPolicy(TenantProfile::DrugWholesaler, ReceivingEdgeMode::CaseOnly))->promptCopy();

        $this->assertStringStartsWith('Case only — receive policy. ', $copy['scanHelper']);
        $this->assertStringContainsString('Scan the case', $copy['scanHelper']);
        $this->assertStringContainsString('pallet', strtolower($copy['scanHelper']));
        $this->assertStringContainsString('bottle', strtolower($copy['scanHelper']));
        $this->assertStringContainsString('case', strtolower($copy['sealedPalletLabel']));
        $this->assertSame('Confirm case + units', $copy['confirmLabelSealed']);
    }

    #[Test]
    public function units_only_prompt_copy_tells_operator_to_scan_unit_2d(): void
    {
        $copy = (new ReceivingPolicy(TenantProfile::DrugWholesaler, ReceivingEdgeMode::UnitsOnly))->promptCopy();

        $this->assertStringStartsWith('Units only — receive policy. ', $copy['scanHelper']);
        $this->assertStringContainsString('unit 2D', $copy['scanHelper']);
        $this->assertStringContainsString('no sealed parent inference', strtolower($copy['sealedPalletLabel']));
        $this->assertSame('Confirm unit', $copy['confirmLabelSealed']);
    }

    #[Test]
    #[DataProvider('palletProfiles')]
    public function distributor_style_prompt_copy_mentions_pallet(TenantProfile $profile): void
    {
        $copy = ReceivingPolicy::forProfile($profile)->promptCopy();

        $this->assertStringContainsString('pallet', strtolower($copy['scanHelper']));
        $this->assertSame('Sealed pallet — confirm all units when I scan the pallet', $copy['sealedPalletLabel']);
        $this->assertSame('Applies to the next pallet scan.', $copy['sealedPalletHelper']);
        $this->assertSame('Confirm pallet + units', $copy['confirmLabelSealed']);
    }

    /**
     * @return array<string, array{0: TenantProfile}>
     */
    public static function palletProfiles(): array
    {
        return [
            'manufacturer' => [TenantProfile::Manufacturer],
            'drug_wholesaler' => [TenantProfile::DrugWholesaler],
            'prepackager' => [TenantProfile::Prepackager],
            'logistics_3pl' => [TenantProfile::Logistics3pl],
            'dental_medical_supply' => [TenantProfile::DentalMedicalSupply],
            'buying_group' => [TenantProfile::BuyingGroup],
        ];
    }
}
