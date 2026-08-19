<?php

namespace Tests\Unit\Support\Gs1;

use App\Support\Gs1\Sgtin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SgtinTest extends TestCase
{
    #[Test]
    public function it_encodes_an_sgtin_urn_into_barcode_fields(): void
    {
        $result = Sgtin::fromUrn('urn:epc:id:sgtin:030116.3400516.10000002877732');

        $this->assertNotNull($result);
        $this->assertSame('urn:epc:id:sgtin:030116.3400516.10000002877732', $result['epc_uri']);
        $this->assertSame('030116', $result['company_prefix']);
        $this->assertSame('3', $result['indicator_digit']);
        $this->assertSame('400516', $result['item_reference']);
        $this->assertSame('10000002877732', $result['serial_number']);
        $this->assertSame('30301164005162', $result['gtin14']);
        $this->assertSame('01303011640051622110000002877732', $result['ai_01_21']);
    }

    #[Test]
    public function it_rejects_invalid_urns(): void
    {
        $this->assertNull(Sgtin::fromUrn('urn:epc:id:sscc:030116.01001235403'));
        $this->assertNull(Sgtin::fromUrn('not-a-urn'));
        // Indicator+CP+itemRef must form exactly 13 digits.
        $this->assertNull(Sgtin::fromUrn('urn:epc:id:sgtin:030116.340051.1'));
    }
}
