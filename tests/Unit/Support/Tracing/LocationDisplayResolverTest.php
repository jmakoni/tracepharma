<?php

namespace Tests\Unit\Support\Tracing;

use App\Enums\PartnerType;
use App\Enums\SsccNumberRangeScope;
use App\Enums\SsccNumberRangeStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\EventLocation;
use App\Models\Site;
use App\Models\SsccNumberRange;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Tracing\LocationDisplayResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocationDisplayResolverTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $siteId = null;

    private ?int $partnerId = null;

    private ?int $rangeId = null;

    #[Test]
    public function named_event_location_without_coords_uses_site_coordinates(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $gln = fake()->unique()->numerify('#############');
            $site = Site::factory()->owned()->create([
                'name' => 'LA Smile HQ',
                'gln' => $gln,
                'latitude' => 34.0522,
                'longitude' => -118.2437,
            ]);
            $this->siteId = (int) $site->getKey();

            $location = new EventLocation([
                'gln' => $gln,
                'name' => 'Receiving dock',
                'latitude' => null,
                'longitude' => null,
            ]);

            $resolved = app(LocationDisplayResolver::class)->resolve($gln, $location);

            $this->assertSame('Receiving dock', $resolved['label']);
            $this->assertSame('Receiving dock', $resolved['name']);
            $this->assertEqualsWithDelta(34.0522, (float) $resolved['latitude'], 0.0001);
            $this->assertEqualsWithDelta(-118.2437, (float) $resolved['longitude'], 0.0001);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function named_event_location_without_coords_uses_partner_coordinates(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $gln = fake()->unique()->numerify('#############');
            $partner = TradingPartner::factory()->create([
                'name' => 'Xttrium Laboratories',
                'gln' => $gln,
                'partner_type' => PartnerType::Manufacturer,
                'latitude' => 41.85,
                'longitude' => -87.65,
            ]);
            $this->partnerId = (int) $partner->getKey();

            $location = new EventLocation([
                'gln' => $gln,
                'name' => 'Xttrium plant',
                'latitude' => null,
                'longitude' => null,
            ]);

            $resolved = app(LocationDisplayResolver::class)->resolve($gln, $location);

            $this->assertSame('Xttrium plant', $resolved['label']);
            $this->assertEqualsWithDelta(41.85, (float) $resolved['latitude'], 0.0001);
            $this->assertEqualsWithDelta(-87.65, (float) $resolved['longitude'], 0.0001);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function address_without_coords_is_geocoded_without_persisting_on_the_site(): void
    {
        $this->initializeDemo2Tenant();
        Cache::store('file')->flush();

        try {
            Http::fake([
                'nominatim.openstreetmap.org/*' => Http::response([
                    ['lat' => '34.052235', 'lon' => '-118.243683'],
                ], 200),
            ]);

            $gln = fake()->unique()->numerify('#############');
            $site = Site::factory()->owned()->create([
                'name' => 'LA Smile HQ',
                'gln' => $gln,
                'street_address' => '123 Sunset Blvd',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'zipcode' => '90012',
                'country_code' => 'US',
                'latitude' => null,
                'longitude' => null,
            ]);
            $this->siteId = (int) $site->getKey();

            $resolved = app(LocationDisplayResolver::class)->resolve($gln);

            $this->assertSame('LA Smile HQ', $resolved['label']);
            $this->assertEqualsWithDelta(34.052235, (float) $resolved['latitude'], 0.0001);
            $this->assertEqualsWithDelta(-118.243683, (float) $resolved['longitude'], 0.0001);

            $site->refresh();
            $this->assertNull($site->latitude);
            $this->assertNull($site->longitude);
            Http::assertSentCount(1);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function event_location_address_is_geocoded_when_master_data_has_no_coords(): void
    {
        $this->initializeDemo2Tenant();
        Cache::store('file')->flush();

        try {
            Http::fake([
                'nominatim.openstreetmap.org/*' => Http::response([
                    ['lat' => '41.850000', 'lon' => '-87.650000'],
                ], 200),
            ]);

            $location = new EventLocation([
                'gln' => fake()->unique()->numerify('#############'),
                'name' => 'Xttrium plant',
                'street_address' => '100 Industrial Pkwy',
                'city' => 'Chicago',
                'state' => 'IL',
                'postal_code' => '60601',
                'country_code' => 'US',
                'latitude' => null,
                'longitude' => null,
            ]);

            $resolved = app(LocationDisplayResolver::class)->resolve($location->gln, $location);

            $this->assertSame('Xttrium plant', $resolved['label']);
            $this->assertEqualsWithDelta(41.85, (float) $resolved['latitude'], 0.0001);
            $this->assertEqualsWithDelta(-87.65, (float) $resolved['longitude'], 0.0001);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function event_location_street_is_not_written_onto_the_site(): void
    {
        $this->initializeDemo2Tenant();
        Cache::store('file')->flush();

        try {
            Http::fake([
                'nominatim.openstreetmap.org/*' => Http::response([
                    ['lat' => '34.010000', 'lon' => '-118.490000'],
                ], 200),
            ]);

            $gln = fake()->unique()->numerify('#############');
            $site = Site::factory()->owned()->create([
                'name' => 'LA Smile HQ',
                'gln' => $gln,
                'street_address' => '100 Campus Dr',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'zipcode' => '90012',
                'country_code' => 'US',
                'latitude' => null,
                'longitude' => null,
            ]);
            $this->siteId = (int) $site->getKey();

            $location = new EventLocation([
                'gln' => $gln,
                'name' => 'Receiving dock',
                'street_address' => '200 Dock St',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90013',
                'country_code' => 'US',
                'latitude' => null,
                'longitude' => null,
            ]);

            $resolved = app(LocationDisplayResolver::class)->resolve($gln, $location);

            $this->assertEqualsWithDelta(34.01, (float) $resolved['latitude'], 0.0001);
            $site->refresh();
            $this->assertNull($site->latitude);
            $this->assertNull($site->longitude);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inactive_partner_geocode_does_not_inactivate_sscc_ranges(): void
    {
        $this->initializeDemo2Tenant();
        Cache::store('file')->flush();

        try {
            Http::fake([
                'nominatim.openstreetmap.org/*' => Http::response([
                    ['lat' => '41.850000', 'lon' => '-87.650000'],
                ], 200),
            ]);

            $partner = TradingPartner::factory()->create([
                'name' => 'Xttrium Laboratories',
                'partner_type' => PartnerType::Manufacturer,
                'street_address' => '100 Industrial Pkwy',
                'city' => 'Chicago',
                'state' => 'IL',
                'zipcode' => '60601',
                'country_code' => 'US',
                'latitude' => null,
                'longitude' => null,
                'is_active' => false,
            ]);
            $this->partnerId = (int) $partner->getKey();

            $range = SsccNumberRange::query()->create([
                'name' => 'Inactive partner range',
                'scope' => SsccNumberRangeScope::Partner,
                'trading_partner_id' => $partner->getKey(),
                'company_prefix' => '0367891',
                'extension_digit' => '0',
                'index' => 1,
                'increment_by' => 1,
                'range_size' => 10000,
                'start_number' => 1,
                'current_number' => 1,
                'threshold_percentage' => 80,
                'status' => SsccNumberRangeStatus::Active,
                'remaining' => 10000,
            ]);
            $this->rangeId = (int) $range->getKey();

            $resolved = app(LocationDisplayResolver::class)->resolve((string) $partner->gln);

            $this->assertEqualsWithDelta(41.85, (float) $resolved['latitude'], 0.0001);
            $partner->refresh();
            $this->assertNull($partner->latitude);
            $this->assertNull($partner->longitude);
            $this->assertSame(SsccNumberRangeStatus::Active, $range->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function two_resolves_of_the_same_address_in_one_request_make_one_http_call(): void
    {
        $this->initializeDemo2Tenant();
        Cache::store('file')->flush();

        try {
            Http::fake([
                'nominatim.openstreetmap.org/*' => Http::response([
                    ['lat' => '34.052235', 'lon' => '-118.243683'],
                ], 200),
            ]);

            $gln = fake()->unique()->numerify('#############');
            $site = Site::factory()->owned()->create([
                'name' => 'LA Smile HQ',
                'gln' => $gln,
                'street_address' => '123 Sunset Blvd',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'zipcode' => '90012',
                'country_code' => 'US',
                'latitude' => null,
                'longitude' => null,
            ]);
            $this->siteId = (int) $site->getKey();

            $resolver = app(LocationDisplayResolver::class);
            $resolver->resolve($gln);
            $resolver->resolve($gln);

            Http::assertSentCount(1);
        } finally {
            $this->cleanup();
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->siteId !== null) {
            Site::query()->whereKey($this->siteId)->delete();
            $this->siteId = null;
        }

        if ($this->rangeId !== null) {
            SsccNumberRange::query()->whereKey($this->rangeId)->delete();
            $this->rangeId = null;
        }

        if ($this->partnerId !== null) {
            TradingPartner::query()->whereKey($this->partnerId)->delete();
            $this->partnerId = null;
        }

        tenancy()->end();
    }
}
