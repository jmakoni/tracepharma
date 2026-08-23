<?php

namespace Tests\Unit\Support\Receiving;

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
    public function pharmacy_prompt_copy_uses_sscc_or_case_placeholder(): void
    {
        $copy = ReceivingPolicy::forProfile(TenantProfile::Pharmacy)->promptCopy();

        $this->assertSame('Sealed tote — Edge-style. Scan SSCC or Case barcode', $copy['scanHelper']);
        $this->assertStringStartsWith('Sealed tote — Edge-style. ', $copy['kindHelper']);
        $this->assertStringContainsString('tote/case', strtolower($copy['sealedPalletLabel']));
        $this->assertSame('Applies to the next tote/case scan.', $copy['sealedPalletHelper']);
        $this->assertSame('Confirm tote/case + units', $copy['confirmLabelSealed']);
        $this->assertSame('Confirm', $copy['confirmLabel']);
    }

    #[Test]
    public function edge_mode_chip_labels_use_edge_style_suffix(): void
    {
        $this->assertSame('Sealed parent — Edge-style', ReceivingEdgeMode::SealedParent->chipLabel());
        $this->assertSame('Sealed tote — Edge-style', ReceivingEdgeMode::ToteLpn->chipLabel());
        $this->assertSame('Open count — Edge-style', ReceivingEdgeMode::OpenCount->chipLabel());
        $this->assertSame('Open tote — Edge-style', ReceivingEdgeMode::OpenTote->chipLabel());
    }

    #[Test]
    public function prompt_copy_names_explicit_open_tote_sop(): void
    {
        $copy = (new ReceivingPolicy(TenantProfile::Pharmacy, ReceivingEdgeMode::OpenTote))->promptCopy();

        $this->assertSame('Open tote — Edge-style. Scan SSCC or Case barcode', $copy['scanHelper']);
        $this->assertStringStartsWith('Open tote — Edge-style. ', $copy['kindHelper']);
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
