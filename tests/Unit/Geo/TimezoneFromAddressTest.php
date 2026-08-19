<?php

namespace Tests\Unit\Geo;

use App\Support\Geo\TimezoneFromAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TimezoneFromAddressTest extends TestCase
{
    #[Test]
    #[DataProvider('usStateProvider')]
    public function resolves_us_state_timezones(string $state, string $expected): void
    {
        $this->assertSame($expected, TimezoneFromAddress::resolve('US', $state));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function usStateProvider(): array
    {
        return [
            'TX' => ['TX', 'America/Chicago'],
            'Texas' => ['Texas', 'America/Chicago'],
            'CA' => ['CA', 'America/Los_Angeles'],
            'NY' => ['NY', 'America/New_York'],
            'AZ' => ['AZ', 'America/Phoenix'],
            'HI' => ['HI', 'Pacific/Honolulu'],
            'AK' => ['AK', 'America/Anchorage'],
        ];
    }

    #[Test]
    public function uses_eastern_tennessee_city_overrides(): void
    {
        $this->assertSame('America/New_York', TimezoneFromAddress::resolve('US', 'TN', 'Chattanooga'));
        $this->assertSame('America/Chicago', TimezoneFromAddress::resolve('US', 'TN', 'Nashville'));
        $this->assertSame('America/Chicago', TimezoneFromAddress::resolve('US', 'TN'));
    }

    #[Test]
    public function returns_null_when_country_or_state_missing_or_unknown(): void
    {
        $this->assertNull(TimezoneFromAddress::resolve(null, 'TX'));
        $this->assertNull(TimezoneFromAddress::resolve('US', null));
        $this->assertNull(TimezoneFromAddress::resolve('US', 'ZZ'));
        $this->assertNull(TimezoneFromAddress::resolve('FR', 'TX'));
    }

    #[Test]
    public function resolves_common_canadian_provinces(): void
    {
        $this->assertSame('America/Toronto', TimezoneFromAddress::resolve('CA', 'ON'));
        $this->assertSame('America/Vancouver', TimezoneFromAddress::resolve('CAN', 'BC'));
    }
}
