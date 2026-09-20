<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Shipping;

use App\Filament\App\Resources\OutboundShippingSessions\Concerns\InteractsWithOutboundShippingSessionHud;
use App\Models\Epcis\EpcisDocument;
use App\Models\Shipping\OutboundShippingSession;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundShipCompleteCopyResidualTest extends TestCase
{
    #[Test]
    public function split_sent_banner_says_session_shipped_not_full_order(): void
    {
        $session = new OutboundShippingSession([
            'expected_count' => 10,
            'confirmed_count' => 4,
            'split_declared' => true,
            'shipping_events_generated_at' => now(),
            'status' => 'completed',
        ]);
        $document = new EpcisDocument([
            'transmission_status' => 'sent',
        ]);
        $session->setRelation('epcisDocument', $document);

        $hud = new class
        {
            use InteractsWithOutboundShippingSessionHud;

            public OutboundShippingSession $record;

            protected function outboundShippingSession(): OutboundShippingSession
            {
                return $this->record;
            }
        };
        $hud->record = $session;

        $copy = $hud->shipCompleteCopy();

        $this->assertSame('Session shipped', $copy['title']);
        $this->assertStringContainsString('still expected on this order', $copy['body']);
        $this->assertStringNotContainsString('All expected', $copy['body']);
    }

    #[Test]
    public function full_sent_banner_says_ship_order_sent(): void
    {
        $session = new OutboundShippingSession([
            'expected_count' => 4,
            'confirmed_count' => 4,
            'split_declared' => false,
            'shipping_events_generated_at' => now(),
            'status' => 'completed',
        ]);
        $document = new EpcisDocument([
            'transmission_status' => 'sent',
        ]);
        $session->setRelation('epcisDocument', $document);

        $hud = new class
        {
            use InteractsWithOutboundShippingSessionHud;

            public OutboundShippingSession $record;

            protected function outboundShippingSession(): OutboundShippingSession
            {
                return $this->record;
            }
        };
        $hud->record = $session;

        $copy = $hud->shipCompleteCopy();

        $this->assertSame('Ship order sent', $copy['title']);
        $this->assertStringNotContainsString('still expected', $copy['body']);
        $this->assertStringNotContainsString('Shipment sent', $copy['title']);
    }
}
