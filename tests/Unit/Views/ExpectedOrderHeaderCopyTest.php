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

    #[Test]
    public function child_rollup_uses_sop_uom_not_eaches(): void
    {
        $html = View::make('filament.app.partials.expected-order-header', [
            'header' => [
                'po' => null,
                'asn' => 'ASN-9',
                'status' => 'open',
                'parents_confirmed' => 1,
                'parents_expected' => 1,
                'eaches_confirmed' => 4,
                'eaches_expected' => 4,
                'child_type_label' => 'Cases',
            ],
        ])->render();

        $this->assertStringContainsString('Cases 4/4', $html);
        $this->assertStringContainsString('Parents 1/1', $html);
        $this->assertStringNotContainsString('Eaches', $html);
        $this->assertStringNotContainsString('48', $html);
        $this->assertStringNotContainsString('Children', $html);
    }

    #[Test]
    public function child_rollup_units_label_for_case_or_tote_sop(): void
    {
        $html = View::make('filament.app.partials.expected-order-header', [
            'header' => [
                'po' => null,
                'asn' => 'ASN-9',
                'status' => 'open',
                'parents_confirmed' => 1,
                'parents_expected' => 1,
                'eaches_confirmed' => 12,
                'eaches_expected' => 12,
                'child_type_label' => 'Units',
            ],
        ])->render();

        $this->assertStringContainsString('Units 12/12', $html);
        $this->assertStringNotContainsString('Eaches', $html);
    }
}
