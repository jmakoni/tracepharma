<?php

namespace Tests\Unit\Support\Gs1;

use App\Support\Gs1\Sscc;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SsccTest extends TestCase
{
    #[Test]
    public function it_encodes_an_sscc_urn_into_barcode_fields(): void
    {
        $result = Sscc::fromUrn('urn:epc:id:sscc:030116.01001235403');

        $this->assertNotNull($result);
        $this->assertSame('urn:epc:id:sscc:030116.01001235403', $result['epc_uri']);
        $this->assertSame('030116', $result['company_prefix']);
        $this->assertSame('0', $result['extension_digit']);
        $this->assertSame('01001235403', $result['serial_reference']);
        $this->assertSame('003011610012354038', $result['sscc18']);
        $this->assertSame('00003011610012354038', $result['ai_00']);
    }

    #[Test]
    public function it_validates_an_18_digit_sscc(): void
    {
        $result = Sscc::fromSscc18('003011610012354038');

        $this->assertNotNull($result);
        $this->assertSame('003011610012354038', $result['sscc18']);
        $this->assertSame('00003011610012354038', $result['ai_00']);
    }

    #[Test]
    public function it_rejects_invalid_urns_and_check_digits(): void
    {
        $this->assertNull(Sscc::fromUrn('urn:epc:id:sgtin:030116.3400516.1'));
        $this->assertNull(Sscc::fromUrn('not-a-urn'));
        $this->assertNull(Sscc::fromSscc18('003011610012354037'));
        $this->assertNull(Sscc::fromSscc18('123'));
    }

    #[Test]
    public function it_builds_an_sscc_urn_and_barcode_fields(): void
    {
        $this->assertSame(
            'urn:epc:id:sscc:030116.01001235403',
            Sscc::toUrn('030116', '0', '1001235403'),
        );

        $result = Sscc::build('030116', '0', '1001235403');

        $this->assertNotNull($result);
        $this->assertSame('urn:epc:id:sscc:030116.01001235403', $result['epc_uri']);
        $this->assertSame('003011610012354038', $result['sscc18']);
    }
}
