<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateSsccDisaggregationEvent;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateSsccDisaggregationEventTest extends TestCase
{
    private const TEST_SETTINGS = ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'];

    #[Test]
    public function test_disaggregation_event_element_order_matches_epcis_sequence(): void
    {
        $xml = app(GenerateSsccDisaggregationEvent::class)->execute(
            'urn:epc:id:sscc:030116.00000210167',
            ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            self::TEST_SETTINGS,
        );

        $parentPos = strpos($xml, '<parentID>');
        $childPos = strpos($xml, '<childEPCs>');
        $actionPos = strpos($xml, '<action>');

        $this->assertNotFalse($parentPos);
        $this->assertNotFalse($childPos);
        $this->assertNotFalse($actionPos);
        $this->assertLessThan($childPos, $parentPos);
        $this->assertLessThan($actionPos, $childPos);
        $this->assertLessThan(strpos($xml, '<bizStep>'), $actionPos);
        $this->assertStringContainsString('<action>DELETE</action>', $xml);
    }

    #[Test]
    public function test_full_cbv_urns_in_settings_are_not_double_prefixed(): void
    {
        $xml = app(GenerateSsccDisaggregationEvent::class)->execute(
            'urn:epc:id:sscc:030116.00000210167',
            ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            [
                ...self::TEST_SETTINGS,
                'biz_step' => 'urn:epcglobal:cbv:bizstep:unpacking',
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            ],
        );

        $this->assertStringContainsString('<bizStep>urn:epcglobal:cbv:bizstep:unpacking</bizStep>', $xml);
        $this->assertStringContainsString('<disposition>urn:epcglobal:cbv:disp:in_progress</disposition>', $xml);
        $this->assertStringNotContainsString('urn:epcglobal:cbv:bizstep:urn:epcglobal:cbv:bizstep:', $xml);
        $this->assertStringNotContainsString('urn:epcglobal:cbv:disp:urn:epcglobal:cbv:disp:', $xml);
    }

    #[Test]
    public function uses_event_time_from_settings_when_provided(): void
    {
        $fixed = Carbon::parse('2026-08-13T01:59:42+00:00');

        $xml = app(GenerateSsccDisaggregationEvent::class)->execute(
            'urn:epc:id:sscc:030116.00000210167',
            ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            [
                ...self::TEST_SETTINGS,
                'event_time' => $fixed,
            ],
        );

        $this->assertStringContainsString('<eventTime>'.$fixed->toIso8601String().'</eventTime>', $xml);
    }
}
