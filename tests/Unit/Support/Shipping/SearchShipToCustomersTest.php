<?php

namespace Tests\Unit\Support\Shipping;

use App\Models\Site;
use App\Support\Shipping\SearchShipToCustomers;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchShipToCustomersTest extends TestCase
{
    #[Test]
    public function format_address_joins_street_city_state_zip(): void
    {
        $site = new Site([
            'street_address' => '123 Autocomplete Ave',
            'street_address_2' => 'Suite 100',
            'city' => 'Dallas',
            'state' => 'TX',
            'zipcode' => '75201',
            'country_code' => 'US',
        ]);

        $this->assertSame(
            '123 Autocomplete Ave, Suite 100, Dallas, TX 75201, US',
            SearchShipToCustomers::formatAddress($site),
        );
    }

    #[Test]
    public function format_address_returns_dash_when_empty(): void
    {
        $this->assertSame('—', SearchShipToCustomers::formatAddress(new Site([])));
    }
}
