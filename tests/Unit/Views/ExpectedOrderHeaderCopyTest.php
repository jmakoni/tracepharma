<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpectedOrderHeaderCopyTest extends TestCase
{
    #[Test]
    public function open_shipment_renders_asn_partial_not_order_partial(): void
    {
        $html = View::make('filament.app.partials.expected-order-header', [
            'header' => [
                'po' => 'PO-1',
                'asn' => 'ASN-9',
                'status' => 'open',
                'parents_confirmed' => 2,
                'parents_expected' => 6,
                'eaches_confirmed' => 0,
                'eaches_expected' => 0,
            ],
        ])->render();

        $this->assertStringContainsString('ASN partial', $html);
        $this->assertStringContainsString('aria-label="Expected ASN ASN-9"', $html);
        $this->assertStringNotContainsString('Order partial', $html);
        $this->assertStringNotContainsString('Order received', $html);
    }

    #[Test]
    public function complete_shipment_renders_asn_complete(): void
    {
        $html = View::make('filament.app.partials.expected-order-header', [
            'header' => [
                'po' => null,
                'asn' => 'ASN-9',
                'status' => 'complete',
                'parents_confirmed' => 6,
                'parents_expected' => 6,
                'eaches_confirmed' => 0,
                'eaches_expected' => 0,
            ],
        ])->render();

        $this->assertStringContainsString('ASN complete', $html);
        $this->assertStringNotContainsString('Order received', $html);
    }
}
