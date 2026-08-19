<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateSsccAggregationEvent;
use App\Enums\OutboundEpcisAggregationMode;
use App\Models\SsccLabel;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateSsccAggregationEventTest extends TestCase
{
    private const TEST_SETTINGS = ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'];

    #[Test]
    public function test_builds_class_only_aggregation_event_with_quantity_children(): void
    {
        $label = new SsccLabel([
            'sscc_urn' => 'urn:epc:id:sscc:030116.00000210168',
        ]);

        $lgtinClass = 'urn:epc:class:lgtin:4054739.099902.P2';
        $xml = app(GenerateSsccAggregationEvent::class)->execute(
            $label,
            [],
            [['epcClass' => $lgtinClass, 'quantity' => 12, 'uom' => 'KGM']],
            OutboundEpcisAggregationMode::ClassOnly,
            self::TEST_SETTINGS,
        );

        $this->assertStringContainsString('<childQuantityList>', $xml);
        $this->assertStringNotContainsString('<childEPCs>', $xml);
        $this->assertStringContainsString($lgtinClass, $xml);
        $this->assertStringContainsString('<quantity>12</quantity>', $xml);
        $this->assertStringContainsString('<uom>KGM</uom>', $xml);
        $this->assertStringContainsString('<action>ADD</action>', $xml);
        $this->assertAggregationElementOrder($xml);
    }

    #[Test]
    public function test_aggregation_event_element_order_matches_epcis_sequence(): void
    {
        $label = new SsccLabel([
            'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
        ]);

        $xml = app(GenerateSsccAggregationEvent::class)->execute(
            $label,
            ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            settings: self::TEST_SETTINGS,
        );

        $this->assertAggregationElementOrder($xml);
    }

    private function assertAggregationElementOrder(string $xml): void
    {
        $parentPos = strpos($xml, '<parentID>');
        $childPos = strpos($xml, '<childEPCs>') ?: strpos($xml, '<childQuantityList>');
        $actionPos = strpos($xml, '<action>');

        $this->assertNotFalse($parentPos);
        $this->assertNotFalse($childPos);
        $this->assertNotFalse($actionPos);
        $this->assertLessThan($childPos, $parentPos);
        $this->assertLessThan($actionPos, $childPos);
        $this->assertLessThan(strpos($xml, '<bizStep>'), $actionPos);
    }

    #[Test]
    public function test_builds_instance_aggregation_event(): void
    {
        $label = new SsccLabel([
            'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
        ]);

        $xml = app(GenerateSsccAggregationEvent::class)->execute(
            $label,
            ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            settings: self::TEST_SETTINGS,
        );

        $this->assertStringContainsString('<childEPCs>', $xml);
        $this->assertStringContainsString('urn:epc:id:sgtin:030116.5200116.00000000413101', $xml);
        $this->assertStringContainsString('urn:epcglobal:cbv:bizstep:packing', $xml);
    }

    #[Test]
    public function test_full_cbv_urns_in_settings_are_not_double_prefixed(): void
    {
        $label = new SsccLabel([
            'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
        ]);

        $xml = app(GenerateSsccAggregationEvent::class)->execute(
            $label,
            ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            settings: [
                ...self::TEST_SETTINGS,
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            ],
        );

        $this->assertStringContainsString('<bizStep>urn:epcglobal:cbv:bizstep:packing</bizStep>', $xml);
        $this->assertStringContainsString('<disposition>urn:epcglobal:cbv:disp:in_progress</disposition>', $xml);
        $this->assertStringNotContainsString('urn:epcglobal:cbv:bizstep:urn:epcglobal:cbv:bizstep:', $xml);
        $this->assertStringNotContainsString('urn:epcglobal:cbv:disp:urn:epcglobal:cbv:disp:', $xml);
    }

    #[Test]
    public function uses_event_time_from_settings_when_provided(): void
    {
        $label = new SsccLabel([
            'sscc_urn' => 'urn:epc:id:sscc:030116.00000210167',
        ]);
        $fixed = Carbon::parse('2026-08-13T01:59:43+00:00');

        $xml = app(GenerateSsccAggregationEvent::class)->execute(
            $label,
            ['urn:epc:id:sgtin:030116.5200116.00000000413101'],
            settings: [
                ...self::TEST_SETTINGS,
                'event_time' => $fixed,
            ],
        );

        $this->assertStringContainsString('<eventTime>'.$fixed->toIso8601String().'</eventTime>', $xml);
        $this->assertStringContainsString('<action>ADD</action>', $xml);
    }
}
