<?php

namespace Tests\Unit;

use App\Models\TradingPartner;
use App\Support\Catalog\PartnerLocationDisplay;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PartnerLocationDisplayTest extends TestCase
{
    #[Test]
    public function formats_mckesson_style_address_and_timezone(): void
    {
        $partner = new TradingPartner([
            'street_address' => '1200 E Business Center Dr',
            'city' => 'Mt Prospect',
            'state' => 'IL',
            'zipcode' => '60056',
            'country_code' => 'US',
            'timezone' => 'America/Chicago',
        ]);

        $this->assertSame(
            [
                '1200 E Business Center Dr',
                'Mt Prospect, IL 60056',
                'United States',
            ],
            PartnerLocationDisplay::addressLines($partner)
        );
        $this->assertSame(
            'Timezone: America/Chicago (Central Time)',
            PartnerLocationDisplay::timezoneLine(PartnerLocationDisplay::resolveTimezone($partner))
        );
    }

    #[Test]
    public function empty_record_returns_null_address_and_timezone(): void
    {
        $partner = new TradingPartner([]);

        $this->assertSame([], PartnerLocationDisplay::addressLines($partner));
        $this->assertNull(PartnerLocationDisplay::addressLine($partner));
        $this->assertNull(PartnerLocationDisplay::timezoneLine(null));
    }

    #[Test]
    public function includes_street_address_2_when_present(): void
    {
        $partner = new TradingPartner([
            'street_address' => '100 Main St',
            'street_address_2' => 'Suite 200',
            'city' => 'Chicago',
            'state' => 'IL',
            'zipcode' => '60601',
            'country_code' => 'US',
        ]);

        $this->assertSame(
            [
                '100 Main St',
                'Suite 200',
                'Chicago, IL 60601',
                'United States',
            ],
            PartnerLocationDisplay::addressLines($partner)
        );
    }
}
