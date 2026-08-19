<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateSsccCommissioningEvent;
use App\Models\SsccLabel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateSsccCommissioningEventTest extends TestCase
{
    #[Test]
    public function test_builds_object_event_commissioning_xml(): void
    {
        $label = new SsccLabel([
            'sscc_18' => '003011610012354038',
            'sscc_urn' => 'urn:epc:id:sscc:030116.01001235403',
        ]);

        $xml = app(GenerateSsccCommissioningEvent::class)->execute(
            $label,
            settings: ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'],
        );

        $this->assertStringContainsString('<ObjectEvent>', $xml);
        $this->assertStringContainsString('urn:epcglobal:cbv:bizstep:commissioning', $xml);
        $this->assertStringContainsString('urn:epcglobal:cbv:disp:active', $xml);
        $this->assertStringContainsString('<action>ADD</action>', $xml);
        $this->assertStringContainsString('urn:epc:id:sscc:030116.01001235403', $xml);
        $this->assertStringContainsString('<readPoint>', $xml);
    }

    #[Test]
    public function test_rejects_invalid_sscc_18_check_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $label = new SsccLabel([
            'sscc_18' => '003011610012354039',
            'sscc_urn' => 'urn:epc:id:sscc:030116.01001235403',
        ]);

        app(GenerateSsccCommissioningEvent::class)->execute(
            $label,
            settings: ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'],
        );
    }

    #[Test]
    public function test_rejects_sscc_urn_mismatching_sscc_18(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $label = new SsccLabel([
            'sscc_18' => '003011610012354038',
            'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
        ]);

        app(GenerateSsccCommissioningEvent::class)->execute(
            $label,
            settings: ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'],
        );
    }
}
