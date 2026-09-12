<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis;

use App\Services\Epcis\EpcisJsonLd20Parser;
use App\Services\Epcis\EpcisXmlParser;
use App\Support\Epcis\EpcisSchemaVersion;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisJsonLd20ParserTwinParityTest extends TestCase
{
    #[Test]
    public function json_2_0_fixture_projects_same_epcs_and_biz_steps_as_xml_1_3_twin(): void
    {
        config(['tracepharma.epcis.accept_20' => true]);

        $xmlPath = base_path('tests/Fixtures/epcis/minimal_object_shipping_1.3.xml');
        $jsonPath = base_path('tests/Fixtures/epcis/minimal_object_packing_2.0.json');

        $xmlEvents = [];
        app(EpcisXmlParser::class)->parseHeaderAndStream($xmlPath, function (array $event) use (&$xmlEvents): void {
            $xmlEvents[] = $event;
        });

        $jsonEvents = [];
        $jsonHeader = app(EpcisJsonLd20Parser::class)->parseHeaderAndStream($jsonPath, function (array $event) use (&$jsonEvents): void {
            $jsonEvents[] = $event;
        });

        $this->assertSame(EpcisSchemaVersion::V20, $jsonHeader['schema_version']);
        $this->assertCount(count($xmlEvents), $jsonEvents);

        foreach ($xmlEvents as $index => $xmlEvent) {
            $jsonEvent = $jsonEvents[$index];
            $this->assertSame($xmlEvent['event_type'], $jsonEvent['event_type'], "event_type mismatch at #{$index}");
            $this->assertSame(
                $this->epcSignature($xmlEvent),
                $this->epcSignature($jsonEvent),
                "epc signature mismatch at #{$index}",
            );
            $this->assertSame(
                strtolower((string) $xmlEvent['biz_step']),
                strtolower((string) $jsonEvent['biz_step']),
                "biz_step mismatch at #{$index}",
            );
            $this->assertSame(
                strtolower((string) $xmlEvent['disposition']),
                strtolower((string) $jsonEvent['disposition']),
                "disposition mismatch at #{$index}",
            );
        }
    }

    #[Test]
    public function parser_rejects_when_accept_20_is_off(): void
    {
        config(['tracepharma.epcis.accept_20' => false]);

        $this->expectException(\InvalidArgumentException::class);

        app(EpcisJsonLd20Parser::class)->parse(
            base_path('tests/Fixtures/epcis/minimal_object_packing_2.0.json'),
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function epcSignature(array $event): string
    {
        $parts = [];
        foreach ($event['epcs'] ?? [] as $epc) {
            $parts[] = ($epc['role'] ?? '').'|'.($epc['uri'] ?? '');
        }
        sort($parts);

        return implode(';', $parts);
    }
}
