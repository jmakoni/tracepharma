<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Receiving;

use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingSession;
use App\Support\Receiving\InboundPoOverReceipt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundPoOverReceiptTest extends TestCase
{
    #[Test]
    public function no_warning_without_shipment_or_po(): void
    {
        $session = new ReceivingSession;
        $session->setRelation('inboundShipment', null);
        $session->setRelation('document', null);

        $this->assertNull(InboundPoOverReceipt::warningFor($session));

        $session->setRelation('inboundShipment', new InboundShipment([
            'customer_po' => null,
            'asn_number' => 'ASN-1',
            'unexpected_count' => 2,
        ]));

        $this->assertNull(InboundPoOverReceipt::warningFor($session));
    }

    #[Test]
    public function no_warning_when_this_asn_is_not_over_and_po_totals_fit(): void
    {
        $shipment = new InboundShipment([
            'id' => 1,
            'trading_partner_key' => 4,
            'customer_po' => 'PO-FIT',
            'asn_number' => 'ASN-A',
            'confirmed_parent_count' => 1,
            'expected_parent_count' => 1,
            'unexpected_count' => 0,
        ]);
        $session = new ReceivingSession;
        $session->setRelation('inboundShipment', $shipment);
        $session->setRelation('document', null);

        $this->assertNull(InboundPoOverReceipt::forShipment($shipment, siblingShipments: collect()));
        $this->assertNull(InboundPoOverReceipt::warningFor($session));
    }

    #[Test]
    public function warns_when_this_asn_has_unexpected_and_names_sibling_asns(): void
    {
        $current = new InboundShipment([
            'id' => 10,
            'trading_partner_key' => 4,
            'customer_po' => 'PO-88',
            'asn_number' => 'ASN-A',
            'confirmed_parent_count' => 1,
            'expected_parent_count' => 1,
            'unexpected_count' => 2,
        ]);
        $sibling = new InboundShipment([
            'id' => 11,
            'trading_partner_key' => 4,
            'customer_po' => 'PO-88',
            'asn_number' => 'ASN-B',
            'confirmed_parent_count' => 1,
            'expected_parent_count' => 1,
            'unexpected_count' => 0,
        ]);

        $warning = InboundPoOverReceipt::forShipment($current, siblingShipments: collect([$sibling]));

        $this->assertNotNull($warning);
        $this->assertStringContainsString('PO-88', $warning);
        $this->assertStringContainsString('ASN-B', $warning);
        $this->assertStringContainsString('2 unexpected', $warning);
        $this->assertStringContainsString('this shipment', $warning);
    }

    #[Test]
    public function warns_when_confirmed_parents_across_po_exceed_known_asn_expected(): void
    {
        $current = new InboundShipment([
            'id' => 20,
            'trading_partner_key' => 4,
            'customer_po' => 'PO-99',
            'asn_number' => 'ASN-A',
            'confirmed_parent_count' => 3,
            'expected_parent_count' => 1,
            'unexpected_count' => 0,
        ]);
        $sibling = new InboundShipment([
            'id' => 21,
            'trading_partner_key' => 4,
            'customer_po' => 'PO-99',
            'asn_number' => 'ASN-B',
            'confirmed_parent_count' => 1,
            'expected_parent_count' => 1,
            'unexpected_count' => 0,
        ]);

        $warning = InboundPoOverReceipt::forShipment($current, siblingShipments: collect([$sibling]));

        $this->assertNotNull($warning);
        $this->assertStringContainsString('PO-99', $warning);
        $this->assertStringContainsString('4/2', $warning);
    }
}
