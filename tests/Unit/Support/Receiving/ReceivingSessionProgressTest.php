<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Models\Receiving\ReceivingSession;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ReceivingSessionProgress;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReceivingSessionProgressTest extends TestCase
{
    #[Test]
    public function asn_shows_confirmed_over_expected_with_pallet_labels(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::InboundAsn,
            'confirmed_parent_count' => 2,
            'expected_parent_count' => 7,
            'confirmed_child_count' => 512,
            'expected_child_count' => 354,
        ]);

        $progress = ReceivingSessionProgress::for(
            $session,
            ReceivingPolicy::forProfile(TenantProfile::DrugWholesaler),
        );

        $this->assertSame('Pallets', $progress->parentTypeLabel());
        $this->assertSame('Cases', $progress->childTypeLabel());
        $this->assertSame('2/7', $progress->parentProgressQuantity());
        $this->assertSame('512/354', $progress->childProgressQuantity());
        $this->assertTrue($progress->showUnitsProgress());
        $this->assertSame('2/7 Pallets · 512/354 Cases', $progress->chipLabel());
    }

    #[Test]
    public function scan_first_shows_confirmed_only(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::ScanFirst,
            'confirmed_parent_count' => 4,
            'expected_parent_count' => 0,
            'confirmed_child_count' => 256,
            'expected_child_count' => 0,
        ]);

        $progress = ReceivingSessionProgress::for(
            $session,
            ReceivingPolicy::forProfile(TenantProfile::DrugWholesaler),
        );

        $this->assertSame('4', $progress->parentProgressQuantity());
        $this->assertSame('256', $progress->childProgressQuantity());
        $this->assertSame('4 Pallets · 256 Cases', $progress->chipLabel());
    }

    #[Test]
    public function transfer_uses_lines_and_hides_units_when_no_expected_children(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::TransferReceive,
            'confirmed_parent_count' => 3,
            'expected_parent_count' => 5,
            'confirmed_child_count' => 0,
            'expected_child_count' => 0,
        ]);

        $progress = ReceivingSessionProgress::for(
            $session,
            ReceivingPolicy::forProfile(TenantProfile::DrugWholesaler),
        );

        $this->assertSame('Lines', $progress->parentTypeLabel());
        $this->assertSame('Units', $progress->childTypeLabel());
        $this->assertSame('3/5', $progress->parentProgressQuantity());
        $this->assertFalse($progress->showUnitsProgress());
        $this->assertSame('3/5 Lines', $progress->chipLabel());
    }

    #[Test]
    public function pharmacy_policy_uses_cases_and_units_labels(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::InboundAsn,
            'confirmed_parent_count' => 1,
            'expected_parent_count' => 2,
            'confirmed_child_count' => 10,
            'expected_child_count' => 20,
        ]);

        $progress = ReceivingSessionProgress::for(
            $session,
            ReceivingPolicy::forProfile(TenantProfile::Pharmacy),
        );

        $this->assertSame('Cases', $progress->parentTypeLabel());
        $this->assertSame('Units', $progress->childTypeLabel());
    }

    #[Test]
    public function staged_offsets_bump_floor_quantities_before_confirm(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::InboundAsn,
            'confirmed_parent_count' => 0,
            'expected_parent_count' => 7,
            'confirmed_child_count' => 0,
            'expected_child_count' => 100,
        ]);

        $progress = ReceivingSessionProgress::for(
            $session,
            ReceivingPolicy::forProfile(TenantProfile::DrugWholesaler),
            stagedParentCount: 1,
            stagedChildCount: 0,
        );

        $this->assertSame('1/7', $progress->parentProgressQuantity());
        $this->assertSame('0/100', $progress->childProgressQuantity());
    }
}
