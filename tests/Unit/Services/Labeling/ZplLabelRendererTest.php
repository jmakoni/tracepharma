<?php

namespace Tests\Unit\Services\Labeling;

use App\Services\Labeling\ZplLabelRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ZplLabelRendererTest extends TestCase
{
    #[Test]
    public function it_renders_gs1_barcode_and_print_quantity(): void
    {
        $zpl = app(ZplLabelRenderer::class)->render([
            'sscc_18' => '003011600002101675',
            'hrt' => '0003011600002101675',
            'ship_to_name' => 'McKesson DC',
            'ship_from_name' => 'Jersey Dental',
            'copies' => 3,
        ]);

        $this->assertStringContainsString('^XA', $zpl);
        $this->assertStringContainsString('^XZ', $zpl);
        $this->assertStringContainsString('0003011600002101675', $zpl);
        $this->assertStringContainsString('^PQ3,0,1,Y', $zpl);
        $this->assertStringContainsString('McKesson DC', $zpl);
    }
}
