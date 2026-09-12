<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Shipping;

use App\Models\Shipping\OutboundShippingSession;
use App\Models\TradingPartner;
use App\Support\Shipping\OutboundShipReadiness;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundShipReadinessTest extends TestCase
{
    #[Test]
    public function badges_block_when_customer_and_ti_are_missing(): void
    {
        $session = new OutboundShippingSession([
            'status' => 'open',
            'trading_partner_id' => null,
            'asn_number' => null,
            'customer_po' => null,
            'invoice_number' => null,
            'dscsa_affirm' => false,
        ]);

        $badges = app(OutboundShipReadiness::class)->badges($session);
        $byKey = collect($badges)->keyBy('key');

        $this->assertSame('block', $byKey['partner']['status']);
        $this->assertSame('block', $byKey['ti_ts']['status']);
        $this->assertSame('block', $byKey['destination']['status']);
    }

    #[Test]
    public function badges_mark_ti_ts_ok_when_affirmed_with_asn_and_po(): void
    {
        $partner = new TradingPartner([
            'name' => 'Buyer Co',
            'is_active' => true,
            'gln' => '0860000000100',
            'email' => 'buyer@example.com',
        ]);

        $session = new OutboundShippingSession([
            'status' => 'open',
            'asn_number' => 'ASN-1',
            'customer_po' => 'PO-1',
            'dscsa_affirm' => true,
            'ship_to_gln' => '0860000000100',
        ]);
        $session->setRelation('tradingPartner', $partner);

        $badges = app(OutboundShipReadiness::class)->badges($session);
        $byKey = collect($badges)->keyBy('key');

        $this->assertSame('ok', $byKey['partner']['status']);
        $this->assertSame('ok', $byKey['ti_ts']['status']);
        $this->assertSame('ok', $byKey['destination']['status']);
        $this->assertSame('ok', $byKey['path']['status']);
    }
}
