<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ResolveGlnToMasterData;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\LocationDevice;
use App\Models\ReadPoint;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Sgln;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveGlnToMasterDataTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantSiteIds = [];

    /** @var list<int> */
    private array $tenantDeviceIds = [];

    /** @var list<int> */
    private array $tenantReadPointIds = [];

    #[Test]
    public function it_resolves_trading_partner_by_gln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('trading_partners'));

            $gln = fake()->unique()->numerify('#############');
            $partner = TradingPartner::query()->create([
                'name' => 'GLN Partner '.uniqid(),
                'gln' => $gln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $partner->id;

            $result = app(ResolveGlnToMasterData::class)->handle(substr($gln, 0, 1).' '.substr($gln, 1));

            $this->assertSame($gln, $result['gln']);
            $this->assertSame((int) $partner->id, $result['trading_partner_id']);
            $this->assertNull($result['site_id']);
            $this->assertNull($result['location_device_id']);
            $this->assertTrue($result['trading_partner']->is($partner));
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function it_resolves_site_gln_with_trading_partner_id(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partnerGln = fake()->unique()->numerify('#############');
            $siteGln = fake()->unique()->numerify('#############');

            $partner = TradingPartner::query()->create([
                'name' => 'Site Parent '.uniqid(),
                'gln' => $partnerGln,
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $partner->id;

            $site = Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'GLN Site '.uniqid(),
                'gln' => $siteGln,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantSiteIds[] = (int) $site->id;

            $result = app(ResolveGlnToMasterData::class)->handle($siteGln);

            $this->assertSame($siteGln, $result['gln']);
            $this->assertSame((int) $partner->id, $result['trading_partner_id']);
            $this->assertSame((int) $site->id, $result['site_id']);
            $this->assertNull($result['location_device_id']);
            $this->assertTrue($result['site']->is($site));
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function it_resolves_location_device_gln_with_site_and_partner(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = TradingPartner::query()->create([
                'name' => 'Device Parent '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $partner->id;

            $site = Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Device Site '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantSiteIds[] = (int) $site->id;

            $deviceGln = fake()->unique()->numerify('#############');
            $device = LocationDevice::query()->create([
                'site_id' => $site->id,
                'name' => 'Dock Door '.uniqid(),
                'gln' => $deviceGln,
            ]);
            $this->tenantDeviceIds[] = (int) $device->id;

            $result = app(ResolveGlnToMasterData::class)->handle($deviceGln);

            $this->assertSame($deviceGln, $result['gln']);
            $this->assertSame((int) $partner->id, $result['trading_partner_id']);
            $this->assertSame((int) $site->id, $result['site_id']);
            $this->assertSame((int) $device->id, $result['location_device_id']);
            $this->assertTrue($result['location_device']->is($device));
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    /**
     * A read point holds no GLN column: an EPCIS readPoint arrives as an SGLN, so the only
     * way back to the dock that authored the event is through the URN.
     */
    #[Test]
    public function it_resolves_a_read_point_through_its_sgln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = TradingPartner::query()->create([
                'name' => 'Read Point Parent '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $partner->id;

            $site = Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Read Point Site '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantSiteIds[] = (int) $site->id;

            // A GLN nothing else owns, encoded with a 7-digit company prefix.
            $body12 = '0619'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $readPointGln = $body12.Gtin::checkDigit($body12);
            $sgln = Sgln::toUrn($readPointGln, 7, '1');
            $this->assertNotNull($sgln);

            $readPoint = ReadPoint::query()->create([
                'site_id' => $site->id,
                'name' => 'Dock Door SGLN '.uniqid(),
                'code' => 'RP-'.strtoupper(uniqid()),
                'sgln' => $sgln,
                'is_active' => true,
            ]);
            $this->tenantReadPointIds[] = (int) $readPoint->id;

            $result = app(ResolveGlnToMasterData::class)->handle($readPointGln);

            $this->assertSame((int) $readPoint->id, $result['read_point_id']);
            $this->assertSame((int) $site->id, $result['site_id']);
            $this->assertSame((int) $partner->id, $result['trading_partner_id']);
            $this->assertTrue($result['read_point']->is($readPoint));
            $this->assertNull($result['location_device_id']);

            // A neighbouring GLN under the same company prefix must not borrow this dock.
            $neighbourBody12 = substr($body12, 0, 11).(string) (((int) substr($body12, 11) + 1) % 10);
            $neighbour = $neighbourBody12.Gtin::checkDigit($neighbourBody12);

            $this->assertNull(app(ResolveGlnToMasterData::class)->handle($neighbour)['read_point_id']);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function it_matches_a_read_point_stored_as_gln_digits_without_prefix_walk(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $source = (string) file_get_contents(base_path('app/Actions/Epcis/ResolveGlnToMasterData.php'));
            $this->assertStringNotContainsString('range(6, 11)', $source);
            $this->assertStringNotContainsString('range(6,11)', $source);

            $partner = TradingPartner::query()->create([
                'name' => 'Stored GLN Parent '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $partner->id;

            $site = Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Stored GLN Site '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantSiteIds[] = (int) $site->id;

            $body12 = '0620'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $readPointGln = $body12.Gtin::checkDigit($body12);

            $readPoint = ReadPoint::query()->create([
                'site_id' => $site->id,
                'name' => 'Dock GLN '.uniqid(),
                'code' => 'RP-GLN-'.strtoupper(uniqid()),
                'sgln' => $readPointGln,
                'is_active' => true,
            ]);
            $this->tenantReadPointIds[] = (int) $readPoint->id;

            $result = app(ResolveGlnToMasterData::class)->handle($readPointGln);

            $this->assertSame((int) $readPoint->id, $result['read_point_id']);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function it_resolves_read_point_when_input_is_stored_sgln_urn(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = TradingPartner::query()->create([
                'name' => 'URN Input Parent '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $partner->id;

            $site = Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'URN Input Site '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantSiteIds[] = (int) $site->id;

            $body12 = '0621'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $readPointGln = $body12.Gtin::checkDigit($body12);
            $sgln = Sgln::toUrn($readPointGln, 7, '1');
            $this->assertNotNull($sgln);

            $readPoint = ReadPoint::query()->create([
                'site_id' => $site->id,
                'name' => 'Dock URN '.uniqid(),
                'code' => 'RP-URN-'.strtoupper(uniqid()),
                'sgln' => $sgln,
                'is_active' => true,
            ]);
            $this->tenantReadPointIds[] = (int) $readPoint->id;

            $result = app(ResolveGlnToMasterData::class)->handle($sgln);

            $this->assertSame((int) $readPoint->id, $result['read_point_id']);
            $this->assertSame($readPointGln, $result['gln']);
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function it_does_not_match_read_point_when_only_six_digit_prefix_overlaps(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = TradingPartner::query()->create([
                'name' => 'Prefix Overlap Parent '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $partner->id;

            $site = Site::query()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Prefix Overlap Site '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantSiteIds[] = (int) $site->id;

            $storedBody12 = '0622'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $storedGln = $storedBody12.Gtin::checkDigit($storedBody12);
            $sgln = Sgln::toUrn($storedGln, 6, '0');
            $this->assertNotNull($sgln);

            $readPoint = ReadPoint::query()->create([
                'site_id' => $site->id,
                'name' => 'Dock Prefix '.uniqid(),
                'code' => 'RP-PX-'.strtoupper(uniqid()),
                'sgln' => $sgln,
                'is_active' => true,
            ]);
            $this->tenantReadPointIds[] = (int) $readPoint->id;

            $overlapBody12 = substr($storedGln, 0, 6).'199999';
            $overlapGln = $overlapBody12.Gtin::checkDigit($overlapBody12);
            $this->assertNotSame($storedGln, $overlapGln);
            $this->assertSame(substr($storedGln, 0, 6), substr($overlapGln, 0, 6));

            $this->assertNull(app(ResolveGlnToMasterData::class)->handle($overlapGln)['read_point_id']);
            $this->assertSame(
                (int) $readPoint->id,
                app(ResolveGlnToMasterData::class)->handle($storedGln)['read_point_id'],
            );
        } finally {
            $this->cleanupIntegrationFixtures();
        }
    }

    #[Test]
    public function it_returns_nulls_when_gln_is_unmatched(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $gln = '9999999999999';
            $result = app(ResolveGlnToMasterData::class)->handle($gln);

            $this->assertSame($gln, $result['gln']);
            $this->assertNull($result['trading_partner_id']);
            $this->assertNull($result['site_id']);
            $this->assertNull($result['location_device_id']);
            $this->assertNull($result['read_point_id']);
            $this->assertNull($result['trading_partner']);
            $this->assertNull($result['site']);
            $this->assertNull($result['location_device']);
            $this->assertNull($result['read_point']);
        } finally {
            $this->cleanupIntegrationFixtures();
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

    private function cleanupIntegrationFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->tenantDeviceIds !== []) {
                LocationDevice::query()->whereIn('id', $this->tenantDeviceIds)->delete();
            }

            if ($this->tenantReadPointIds !== []) {
                ReadPoint::query()->whereIn('id', $this->tenantReadPointIds)->delete();
            }

            if ($this->tenantSiteIds !== []) {
                Site::query()->whereIn('id', $this->tenantSiteIds)->delete();
            }

            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }

            tenancy()->end();
        }

        $this->tenantPartnerIds = [];
        $this->tenantSiteIds = [];
        $this->tenantDeviceIds = [];
        $this->tenantReadPointIds = [];
    }
}
