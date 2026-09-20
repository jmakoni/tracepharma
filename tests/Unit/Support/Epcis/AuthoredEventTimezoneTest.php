<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Epcis;

use App\Models\Site;
use App\Support\Epcis\AuthoredEventTimezone;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthoredEventTimezoneTest extends TestCase
{
    #[Test]
    public function uses_site_timezone_when_site_has_timezone_set(): void
    {
        config(['app.timezone' => 'UTC']);

        $site = new Site(['timezone' => 'America/Chicago']);
        $at = Carbon::parse('2026-01-15 12:00:00', 'UTC');

        $offset = AuthoredEventTimezone::offsetForSite($site, $at);

        $this->assertSame('-06:00', $offset);
        $this->assertNotSame('+00:00', $offset);
    }

    #[Test]
    public function falls_back_to_app_timezone_when_site_timezone_is_null(): void
    {
        config(['app.timezone' => 'America/Denver']);

        $site = new Site(['timezone' => null]);
        $at = Carbon::parse('2026-01-15 12:00:00', 'UTC');

        $this->assertSame(
            $at->clone()->timezone('America/Denver')->format('P'),
            AuthoredEventTimezone::offsetForSite($site, $at),
        );
    }

    #[Test]
    public function falls_back_to_utc_when_site_and_app_timezone_are_invalid(): void
    {
        config(['app.timezone' => 'Not/A/Zone']);

        $site = new Site(['timezone' => 'Also/Invalid']);
        $at = Carbon::parse('2026-01-15 12:00:00', 'UTC');

        $this->assertSame('+00:00', AuthoredEventTimezone::offsetForSite($site, $at));
    }
}
