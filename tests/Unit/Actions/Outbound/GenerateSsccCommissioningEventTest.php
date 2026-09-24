<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Outbound;

use App\Actions\Outbound\GenerateSsccCommissioningEvent;
use App\Models\SsccLabel;
use App\Support\Epcis\AuthoredEventTimezone;
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
        $this->assertStringContainsString(
            '<eventTimeZoneOffset>'.AuthoredEventTimezone::offsetForSite(null).'</eventTimeZoneOffset>',
            $xml,
        );
        $this->assertStringNotContainsString('<ilmd>', $xml);
        $this->assertStringNotContainsString('lotNumber', $xml);
    }

    #[Test]
    public function timezone_offset_follows_app_timezone_not_hardcoded_utc(): void
    {
        config(['app.timezone' => 'America/New_York']);

        $label = new SsccLabel([
            'sscc_18' => '003011610012354038',
            'sscc_urn' => 'urn:epc:id:sscc:030116.01001235403',
        ]);

        $xml = app(GenerateSsccCommissioningEvent::class)->execute(
            $label,
            settings: ['sgln_urn' => 'urn:epc:id:sgln:030116.00000.0'],
        );

        $offset = AuthoredEventTimezone::offsetForSite(null);
        $this->assertNotSame('+00:00', $offset);
        $this->assertStringContainsString('<eventTimeZoneOffset>'.$offset.'</eventTimeZoneOffset>', $xml);
    }

    #[Test]
    public function uses_biz_location_site_timezone_when_site_id_is_provided(): void
    {
        $source = (string) file_get_contents(base_path('app/Actions/Outbound/GenerateSsccCommissioningEvent.php'));
        $this->assertStringContainsString('Site::query()->find($siteId)', $source);
        $this->assertStringContainsString(
            'AuthoredEventTimezone::offsetForSite($site instanceof Site ? $site : null)',
            $source,
        );
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
