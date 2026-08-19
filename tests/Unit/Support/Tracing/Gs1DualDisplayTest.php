<?php

namespace Tests\Unit\Support\Tracing;

use App\Models\Epcis\Epc;
use App\Support\Tracing\Gs1DualDisplay;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class Gs1DualDisplayTest extends TestCase
{
    #[Test]
    public function it_builds_dual_display_for_an_sscc_epc(): void
    {
        $epc = new Epc([
            'epc_uri' => 'urn:epc:id:sscc:030116.01001235403',
            'epc_type' => 'sscc',
            'sscc18' => '003011610012354038',
            'ai_00' => '00003011610012354038',
        ]);

        $result = Gs1DualDisplay::forEpc($epc);

        $this->assertSame('003011610012354038', $result['primary']);
        $this->assertSame('(00)003011610012354038', $result['gs1_barcode']);
        $this->assertSame('urn:epc:id:sscc:030116.01001235403', $result['urn']);
    }

    #[Test]
    public function it_builds_dual_display_for_an_sgtin_epc(): void
    {
        $epc = new Epc([
            'epc_uri' => 'urn:epc:id:sgtin:030116.3400516.10000002877732',
            'epc_type' => 'sgtin',
            'gtin14' => '30301164005162',
            'serial_number' => '10000002877732',
            'ai_01_21' => '01303011640051622110000002877732',
        ]);

        $result = Gs1DualDisplay::forEpc($epc);

        $this->assertSame('30301164005162 · 10000002877732', $result['primary']);
        $this->assertSame('01303011640051622110000002877732', $result['gs1_barcode']);
        $this->assertSame('urn:epc:id:sgtin:030116.3400516.10000002877732', $result['urn']);
    }

    #[Test]
    public function it_derives_sscc18_from_ai_00_when_column_is_empty(): void
    {
        $epc = new Epc([
            'epc_type' => 'sscc',
            'ai_00' => '00003011610012354038',
        ]);

        $result = Gs1DualDisplay::forEpc($epc);

        $this->assertSame('003011610012354038', $result['primary']);
        $this->assertSame('(00)003011610012354038', $result['gs1_barcode']);
    }

    #[Test]
    public function it_falls_back_gracefully_for_unknown_epc_types(): void
    {
        $epc = new Epc([
            'epc_uri' => 'urn:epc:id:sgln:030116.000000.0',
            'epc_type' => 'sgln',
        ]);

        $result = Gs1DualDisplay::forEpc($epc);

        $this->assertSame('urn:epc:id:sgln:030116.000000.0', $result['primary']);
        $this->assertSame('—', $result['gs1_barcode']);
        $this->assertSame('urn:epc:id:sgln:030116.000000.0', $result['urn']);
    }

    #[Test]
    public function it_builds_dual_display_from_sscc_identity_keys(): void
    {
        $result = Gs1DualDisplay::forIdentity([
            'sscc18' => '003011610012354038',
            'ai_00' => '00003011610012354038',
        ]);

        $this->assertSame('003011610012354038', $result['primary']);
        $this->assertSame('(00)003011610012354038', $result['gs1_barcode']);
        $this->assertSame('', $result['urn']);
    }

    #[Test]
    public function it_builds_dual_display_from_sgtin_identity_keys(): void
    {
        $result = Gs1DualDisplay::forIdentity([
            'gtin14' => '30301164005162',
            'serial' => '10000002877732',
            'ai_01_21' => '01303011640051622110000002877732',
        ]);

        $this->assertSame('30301164005162 · 10000002877732', $result['primary']);
        $this->assertSame('01303011640051622110000002877732', $result['gs1_barcode']);
    }

    #[Test]
    public function it_returns_placeholder_display_for_empty_identity(): void
    {
        $result = Gs1DualDisplay::forIdentity([]);

        $this->assertSame('—', $result['primary']);
        $this->assertSame('—', $result['gs1_barcode']);
        $this->assertSame('', $result['urn']);
    }
}
