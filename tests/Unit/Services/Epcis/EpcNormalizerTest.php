<?php

namespace Tests\Unit\Services\Epcis;

use App\Services\Epcis\EpcNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EpcNormalizerTest extends TestCase
{
    private EpcNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new EpcNormalizer(static fn (): null => null);
    }

    #[Test]
    public function it_normalizes_an_sgtin_urn(): void
    {
        $result = $this->normalizer->fromUri('urn:epc:id:sgtin:030116.3400516.10000002877732');

        $this->assertNotNull($result);
        $this->assertSame('urn:epc:id:sgtin:030116.3400516.10000002877732', $result['epc_uri']);
        $this->assertSame('sgtin', $result['epc_type']);
        $this->assertSame('030116', $result['company_prefix']);
        $this->assertSame(3, $result['indicator_digit']);
        $this->assertSame('400516', $result['item_reference']);
        $this->assertSame('10000002877732', $result['serial_number']);
        $this->assertSame('30301164005162', $result['gtin14']);
        $this->assertSame('01303011640051622110000002877732', $result['ai_01_21']);
        $this->assertArrayNotHasKey('product_id', $result);
    }

    #[Test]
    public function it_normalizes_an_sscc_urn(): void
    {
        $result = $this->normalizer->fromUri('urn:epc:id:sscc:030116.01001235403');

        $this->assertNotNull($result);
        $this->assertSame('urn:epc:id:sscc:030116.01001235403', $result['epc_uri']);
        $this->assertSame('sscc', $result['epc_type']);
        $this->assertSame('030116', $result['company_prefix']);
        $this->assertSame(0, $result['extension_digit']);
        $this->assertSame('01001235403', $result['serial_number']);
        $this->assertSame('003011610012354038', $result['sscc18']);
        $this->assertSame('00003011610012354038', $result['ai_00']);
    }

    #[Test]
    public function it_rejects_invalid_urns(): void
    {
        $this->assertNull($this->normalizer->fromUri('not-a-urn'));
        $this->assertNull($this->normalizer->fromUri(''));
        $this->assertNull($this->normalizer->fromUri('urn:epc:id:sgln:030116.000000.0'));
    }

    #[Test]
    public function it_normalizes_unparenthesized_sgtin_ai_string(): void
    {
        $result = $this->normalizer->fromAiElementString('01303011640051622110000002877732');

        $this->assertNotNull($result);
        $this->assertSame('sgtin', $result['epc_type']);
        $this->assertSame('30301164005162', $result['gtin14']);
        $this->assertSame('10000002877732', $result['serial_number']);
        $this->assertSame('01303011640051622110000002877732', $result['ai_01_21']);
        $this->assertArrayNotHasKey('epc_uri', $result);
    }

    #[Test]
    public function it_normalizes_parenthesized_sgtin_with_lot_and_expiry(): void
    {
        $result = $this->normalizer->fromAiElementString(
            '(01)30301164005162(21)10000002877732(17)260731(10)LOT-A1'
        );

        $this->assertNotNull($result);
        $this->assertSame('sgtin', $result['epc_type']);
        $this->assertSame('30301164005162', $result['gtin14']);
        $this->assertSame('10000002877732', $result['serial_number']);
        $this->assertSame('01303011640051622110000002877732', $result['ai_01_21']);
        $this->assertSame('LOT-A1', $result['lot_number']);
        $this->assertSame('260731', $result['expiry_yymmdd']);
    }

    #[Test]
    public function it_accepts_three_sscc_scan_forms(): void
    {
        $sscc18 = '003011610012354038';
        $ai00 = '00003011610012354038';

        foreach ([$sscc18, $ai00, '(00)'.$sscc18] as $input) {
            $result = $this->normalizer->fromAiElementString($input);
            $this->assertNotNull($result, "Failed for input: {$input}");
            $this->assertSame('sscc', $result['epc_type']);
            $this->assertSame($sscc18, $result['sscc18']);
            $this->assertSame($ai00, $result['ai_00']);
            $this->assertArrayNotHasKey('epc_uri', $result);
        }
    }
}
