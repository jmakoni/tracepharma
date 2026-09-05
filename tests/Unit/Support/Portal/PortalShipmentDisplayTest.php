<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Portal;

use App\Models\Epcis\EpcisDocument;
use App\Support\Portal\PortalShipmentDisplay;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PortalShipmentDisplayTest extends TestCase
{
    #[Test]
    public function it_prefers_po_for_label(): void
    {
        $document = new EpcisDocument([
            'customer_po' => 'PO-JOEL-001',
            'asn_number' => 'ASN-001',
            'document_uuid' => '11111111-2222-3333-4444-555555555555',
        ]);

        $this->assertSame('PO-JOEL-001', PortalShipmentDisplay::label($document));
        $this->assertSame('ASN ASN-001 · 11111111', PortalShipmentDisplay::subtitle($document));
    }

    #[Test]
    public function it_allows_reports_for_parsed_and_error_with_payload(): void
    {
        $parsed = new EpcisDocument(['status' => 'parsed']);
        $this->assertTrue(PortalShipmentDisplay::reportsAvailable($parsed));

        $blocked = new EpcisDocument(['status' => 'error', 'payload_path' => null]);
        $this->assertFalse(PortalShipmentDisplay::reportsAvailable($blocked));

        Storage::fake('local');
        $withPayload = new EpcisDocument([
            'status' => 'error',
            'payload_path' => 'epcis/outbound/test.xml',
            'payload_disk' => 'local',
        ]);
        Storage::disk('local')->put('epcis/outbound/test.xml', '<epcis/>');
        $this->assertTrue(PortalShipmentDisplay::reportsAvailable($withPayload));
    }
}
