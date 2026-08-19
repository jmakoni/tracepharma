<?php

namespace Tests\Unit\Support\Gs1;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Tracing\Gs1DualDisplay;
use App\Support\Tracing\VerifyUrlParams;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcBarcodeDisplayTest extends TestCase
{
    #[Test]
    public function it_includes_lot_and_expiry_on_gs1_dual_display_barcode(): void
    {
        $epc = new Epc([
            'epc_type' => 'sgtin',
            'gtin14' => '50301164005081',
            'serial_number' => '10000000172110',
            'epc_uri' => 'urn:epc:id:sgtin:030116.4500508.10000000172110',
        ]);
        $epc->setRelation('ilmd', new EpcIlmd([
            'lot_number' => '511115A',
            'expiry_date' => Carbon::create(2027, 10, 31),
        ]));

        $result = Gs1DualDisplay::forEpc($epc);

        $this->assertSame('50301164005081 · 10000000172110', $result['primary']);
        $this->assertSame('015030116400508121100000001721101727103110511115A', $result['gs1_barcode']);
    }

    #[Test]
    public function dual_display_identity_appends_lot_and_expiry_from_scan_keys(): void
    {
        $result = Gs1DualDisplay::forIdentity([
            'gtin14' => '50301164005081',
            'serial' => '10000000172110',
            'lot_number' => '511115A',
            'expiry_yymmdd' => '271031',
        ]);

        $this->assertSame('50301164005081 · 10000000172110', $result['primary']);
        $this->assertSame('015030116400508121100000001721101727103110511115A', $result['gs1_barcode']);
    }

    #[Test]
    public function verify_url_params_use_the_concatenated_sgtin_element_string(): void
    {
        $epc = new Epc([
            'epc_type' => 'sgtin',
            'gtin14' => '50301164005081',
            'serial_number' => '10000000172110',
        ]);
        $epc->setRelation('ilmd', new EpcIlmd([
            'lot_number' => '511115A',
            'expiry_date' => Carbon::create(2027, 10, 31),
        ]));

        $this->assertSame(
            [
                'barcode' => '015030116400508121100000001721101727103110511115A',
                'gtin' => '50301164005081',
                'serial' => '10000000172110',
            ],
            VerifyUrlParams::forEpc($epc),
        );
    }

    #[Test]
    public function it_encodes_sgtin_with_lot_and_expiry_from_ilmd(): void
    {
        $epc = new Epc([
            'epc_type' => 'sgtin',
            'gtin14' => '50301164005081',
            'serial_number' => '10000000172110',
            'ai_01_21' => '01503011640050812110000000172110',
        ]);
        $epc->setRelation('ilmd', new EpcIlmd([
            'lot_number' => '511115A',
            'expiry_date' => Carbon::create(2027, 10, 31),
        ]));

        $this->assertSame(
            '015030116400508121100000001721101727103110511115A',
            EpcBarcodeDisplay::forEpc($epc),
        );
    }

    #[Test]
    public function it_falls_back_to_01_21_when_ilmd_is_missing(): void
    {
        $epc = new Epc([
            'epc_type' => 'sgtin',
            'gtin14' => '50301164005081',
            'serial_number' => '10000000172110',
            'ai_01_21' => '01503011640050812110000000172110',
        ]);
        $epc->setRelation('ilmd', null);

        $this->assertSame(
            '01503011640050812110000000172110',
            EpcBarcodeDisplay::forEpc($epc),
        );
    }

    #[Test]
    public function it_prefers_sscc18_for_pallet_labels(): void
    {
        $epc = new Epc([
            'epc_type' => 'sscc',
            'sscc18' => '003011610012354038',
            'ai_00' => '00003011610012354038',
        ]);

        $this->assertSame('003011610012354038', EpcBarcodeDisplay::forEpc($epc));
    }

    #[Test]
    public function it_falls_back_to_ai_00_when_sscc18_is_empty(): void
    {
        $epc = new Epc([
            'epc_type' => 'sscc',
            'ai_00' => '00003011610012354038',
        ]);

        $this->assertSame('00003011610012354038', EpcBarcodeDisplay::forEpc($epc));
    }
}
