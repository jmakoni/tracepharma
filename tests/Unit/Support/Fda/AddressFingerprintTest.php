<?php

namespace Tests\Unit\Support\Fda;

use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\DecrsAddressParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AddressFingerprintTest extends TestCase
{
    #[Test]
    public function us_decrs_address_parses_street_city_state_zip(): void
    {
        $parsed = DecrsAddressParser::parse(
            '233 S. Secrest Ave, Monroe, North Carolina (NC) 28112, United States (USA)'
        );

        $this->assertSame('233 S. Secrest Ave', $parsed['street_address']);
        $this->assertSame('Monroe', $parsed['city']);
        $this->assertSame('NC', $parsed['state_province']);
        $this->assertSame('28112', $parsed['postal_code']);
        $this->assertSame('US', $parsed['country_code']);
    }

    #[Test]
    public function foreign_decrs_address_keeps_iso_country(): void
    {
        $parsed = DecrsAddressParser::parse(
            'Rue Grands Navoirs, Chauny,  F-02300, France (FRA)'
        );

        $this->assertSame('FR', $parsed['country_code']);
        $this->assertSame('Chauny', $parsed['city']);
        $this->assertSame('F-02300', $parsed['postal_code']);
        $this->assertSame(
            'Rue Grands Navoirs, Chauny,  F-02300, France (FRA)',
            $parsed['full_address']
        );
    }

    #[Test]
    public function foreign_region_is_not_stored_as_postal_code(): void
    {
        $parsed = DecrsAddressParser::parse(
            '15, Minsheng St., Tucheng District, New Taipei City 236044, Taiwan (TWN)'
        );

        $this->assertSame('TW', $parsed['country_code']);
        $this->assertSame('Tucheng District', $parsed['city']);
        $this->assertSame('New Taipei City', $parsed['state_province']);
        $this->assertSame('236044', $parsed['postal_code']);
        $this->assertLessThanOrEqual(20, strlen((string) $parsed['postal_code']));
    }

    #[Test]
    public function same_physical_us_site_shares_a_fingerprint(): void
    {
        $fromDecrs = AddressFingerprint::fromParsed(DecrsAddressParser::parse(
            '100 Alpha Way, Austin, Texas (TX) 78701, United States (USA)'
        ));
        $fromWdd = AddressFingerprint::fromWdd('100 Alpha Way', 'Austin', 'TX', '78701');

        $this->assertSame($fromDecrs, $fromWdd);
        $this->assertSame(64, strlen($fromDecrs));
    }

    #[Test]
    public function zip_plus4_collapses_to_zip5_for_us(): void
    {
        $five = AddressFingerprint::fromWdd('100 Alpha Way', 'Austin', 'TX', '78701');
        $plus4 = AddressFingerprint::fromWdd('100 Alpha Way', 'Austin', 'TX', '78701-1234');

        $this->assertSame($five, $plus4);
    }
}
