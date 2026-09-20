<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Models\Receiving\ReceivingSession;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ReceivingSessionCompleteCopy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReceivingSessionCompleteCopyTest extends TestCase
{
    #[Test]
    public function scan_first_uses_session_complete_never_all_expected(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::ScanFirst,
            'confirmed_parent_count' => 1,
            'confirmed_child_count' => 3,
        ]);
        $session->setRelation('inboundShipment', null);
        $session->setRelation('document', null);

        $copy = ReceivingSessionCompleteCopy::for(
            $session,
            ReceivingPolicy::forProfile(TenantProfile::DrugWholesaler),
        );

        $this->assertSame('Session complete', $copy['title']);
        $this->assertStringNotContainsString('All expected', $copy['body']);
        $this->assertStringNotContainsString('Receiving complete', $copy['title']);
        $this->assertStringNotContainsString('ASN complete', $copy['title']);
        $this->assertTrue($copy['document_complete']);
    }

    #[Test]
    public function scan_first_case_scan_counts_top_container_not_auto_confirmed_children(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::ScanFirst,
            'confirmed_parent_count' => 1,
            'confirmed_child_count' => 105,
        ]);
        $session->setRelation('inboundShipment', null);
        $session->setRelation('document', null);

        $policy = new ReceivingPolicy(TenantProfile::DrugWholesaler, ReceivingEdgeMode::CaseOnly);
        $copy = ReceivingSessionCompleteCopy::for($session, $policy);

        $this->assertSame('Session complete', $copy['title']);
        $this->assertSame('1 case received this session.', $copy['body']);
        $this->assertStringNotContainsString('106', $copy['body']);
        $this->assertStringNotContainsString('items', $copy['body']);
        $this->assertStringNotContainsString('105', $copy['body']);
        $this->assertSame(1, ReceivingSessionCompleteCopy::operatorScannedCount($session));
    }

    #[Test]
    public function asn_without_shipment_counts_parents_only_when_parents_confirmed(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::InboundAsn,
            'confirmed_parent_count' => 2,
            'confirmed_child_count' => 10,
        ]);
        $session->setRelation('inboundShipment', null);
        $session->setRelation('document', null);

        $copy = ReceivingSessionCompleteCopy::for(
            $session,
            ReceivingPolicy::forProfile(TenantProfile::DrugWholesaler),
        );

        $this->assertSame('Session complete', $copy['title']);
        $this->assertSame('2 pallets received this session.', $copy['body']);
        $this->assertStringNotContainsString('10', $copy['body']);
        $this->assertStringNotContainsString('All expected', $copy['body']);
    }

    #[Test]
    public function units_only_session_reports_leaf_scans_when_no_parents(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::ScanFirst,
            'confirmed_parent_count' => 0,
            'confirmed_child_count' => 3,
        ]);
        $session->setRelation('inboundShipment', null);
        $session->setRelation('document', null);

        $policy = new ReceivingPolicy(TenantProfile::DrugWholesaler, ReceivingEdgeMode::UnitsOnly);
        $copy = ReceivingSessionCompleteCopy::for($session, $policy);

        $this->assertSame('3 units received this session.', $copy['body']);
        $this->assertSame(3, ReceivingSessionCompleteCopy::operatorScannedCount($session));
    }

    #[Test]
    public function transfer_with_no_remaining_lines_is_document_complete(): void
    {
        $session = new ReceivingSession([
            'session_kind' => ReceivingSessionKind::TransferReceive,
            'transferring_session_id' => null,
            'confirmed_parent_count' => 2,
            'confirmed_child_count' => 0,
        ]);
        $session->setRelation('inboundShipment', null);
        $session->setRelation('document', null);

        $copy = ReceivingSessionCompleteCopy::for(
            $session,
            ReceivingPolicy::forProfile(TenantProfile::DrugWholesaler),
        );

        $this->assertSame('Transfer complete', $copy['title']);
        $this->assertTrue($copy['document_complete']);
        $this->assertStringContainsString('All expected lines on this transfer', $copy['body']);
    }
}
