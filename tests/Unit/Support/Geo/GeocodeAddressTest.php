<?php

namespace Tests\Unit\Support\Geo;

use App\Support\Geo\GeocodeAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeocodeAddressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('file')->flush();
    }

    #[Test]
    public function it_geocodes_a_full_address_via_nominatim(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '34.052235', 'lon' => '-118.243683'],
            ], 200),
        ]);

        $result = app(GeocodeAddress::class)->handle('123 Main St, Los Angeles, CA 90012 US');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(34.052235, $result['latitude'], 0.0001);
        $this->assertEqualsWithDelta(-118.243683, $result['longitude'], 0.0001);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_caches_a_successful_lookup(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '41.85', 'lon' => '-87.65'],
            ], 200),
        ]);

        $geocoder = app(GeocodeAddress::class);
        $first = $geocoder->handle('100 Industrial Pkwy, Chicago, IL 60601 US');
        $second = $geocoder->handle('100 Industrial Pkwy, Chicago, IL 60601 US');

        $this->assertEqualsWithDelta(41.85, $first['latitude'], 0.0001);
        $this->assertEqualsWithDelta(41.85, $second['latitude'], 0.0001);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_skips_thin_addresses_and_failed_lookups(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $geocoder = app(GeocodeAddress::class);

        $this->assertNull($geocoder->handle(null));
        $this->assertNull($geocoder->handle('US'));
        $this->assertNull($geocoder->handle('123 Main St, Springfield, IL 62701 US'));
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_memoizes_the_same_address_within_one_request(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '34.05', 'lon' => '-118.24'],
            ], 200),
        ]);

        $geocoder = app(GeocodeAddress::class);
        $geocoder->handle('123 Sunset Blvd, Los Angeles, CA 90012 US');
        $geocoder->handle('123 Sunset Blvd, Los Angeles, CA 90012 US');

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_does_not_cache_a_429_as_a_long_miss(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $address = '123 Sunset Blvd, Los Angeles, CA 90012 US';
        $this->assertNull(app(GeocodeAddress::class)->handle($address));

        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $address) ?? ''));
        $this->assertFalse(Cache::store('file')->has('geocode:'.hash('sha256', $normalized)));
    }
}
