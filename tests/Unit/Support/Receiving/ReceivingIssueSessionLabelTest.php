<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Receiving;

use App\Enums\ReceivingSessionKind;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Receiving\ReceivingIssueSessionLabel;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceivingIssueSessionLabelTest extends TestCase
{
    #[Test]
    public function asn_session_leads_with_po_and_asn(): void
    {
        $session = $this->makeLabeledSession(
            ReceivingSessionKind::InboundAsn,
            new InboundShipment([
                'customer_po' => 'PO-123',
                'asn_number' => 'ASN-9',
            ]),
        );

        $label = ReceivingIssueSessionLabel::for($session);

        $this->assertStringStartsWith('PO-123 · ASN-9 · ', $label);
        $this->assertStringContainsString('Acme', $label);
        $this->assertStringContainsString('HQ', $label);
        $this->assertStringNotContainsString('#', $label);
    }

    #[Test]
    public function hides_synthetic_doc_asn_and_blank_po(): void
    {
        $session = $this->makeLabeledSession(
            ReceivingSessionKind::InboundAsn,
            new InboundShipment([
                'customer_po' => null,
                'asn_number' => 'DOC:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            ]),
        );

        $label = ReceivingIssueSessionLabel::for($session);

        $this->assertStringStartsWith('Acme · HQ · ', $label);
        $this->assertStringNotContainsString('DOC:', $label);
        $this->assertStringNotContainsString('PO-', $label);
    }

    #[Test]
    public function scan_first_without_shipment_keeps_session_id(): void
    {
        $session = $this->makeLabeledSession(ReceivingSessionKind::ScanFirst, null);
        $session->id = 7;

        $label = ReceivingIssueSessionLabel::for($session);

        $this->assertStringStartsWith('#7 · Acme · HQ · ', $label);
        $this->assertStringNotContainsString('ASN', $label);
        $this->assertStringNotContainsString('PO-', $label);
    }

    private function makeLabeledSession(ReceivingSessionKind $kind, ?InboundShipment $shipment): ReceivingSession
    {
        $session = new ReceivingSession([
            'session_kind' => $kind,
            'completed_at' => Carbon::parse('2026-09-22 00:46:00', config('app.timezone')),
        ]);
        $session->setRelation('inboundShipment', $shipment);
        $session->setRelation('document', null);
        $session->setRelation('tradingPartner', new TradingPartner(['name' => 'Acme']));
        $session->setRelation('site', new Site(['name' => 'HQ']));

        return $session;
    }
}
