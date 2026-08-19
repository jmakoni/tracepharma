<?php

namespace Tests\Feature\MasterData;

use App\Actions\Demo\SeedMasterData;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\OutboundConnection;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeedOutboundShipDemoTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const CONNECTION_NAME = 'Demo Downstream Pharmacy HTTPS';

    private const DOWNSTREAM_GLN = '0614141000005';

    private const LEGACY_GLN = '0614141000003';

    private const WHOLESALER_GLN = '0614141000001';

    private static bool $demo2TenantReady = false;

    /**
     * The legacy …0003 partner carries an invalid GS1 check digit and no SGLN, so a
     * connection left pointing at it addresses shipments to a party the EPCIS
     * destinationList cannot name. Re-pointing has to happen on every seed run, not only
     * when the connection is first created.
     */
    #[Test]
    public function seed_repoints_the_demo_outbound_connection_off_the_legacy_partner(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(SeedMasterData::class)->handle();

            $legacy = TradingPartner::query()->firstOrCreate(
                ['gln' => self::LEGACY_GLN],
                [
                    'name' => 'Legacy Downstream Pharmacy',
                    'partner_type' => PartnerType::Pharmacy,
                    'is_active' => true,
                ],
            );

            OutboundConnection::query()->updateOrCreate(
                ['name' => self::CONNECTION_NAME],
                [
                    'serialization_provider' => SerializationProvider::CustomHttps,
                    'transport' => OutboundTransport::Https,
                    'trading_partner_id' => $legacy->id,
                    'is_active' => true,
                    'settings' => ['endpoint_url' => 'https://example.com/epcis-inbound'],
                    'credentials' => ['webhook_token' => 'demo-outbound-token'],
                ],
            );

            app(SeedMasterData::class)->handle();

            $downstream = TradingPartner::query()->where('gln', self::DOWNSTREAM_GLN)->first();
            $this->assertNotNull($downstream, 'Seed must ensure the …0005 downstream pharmacy.');

            $connection = OutboundConnection::query()->where('name', self::CONNECTION_NAME)->first();
            $this->assertNotNull($connection);
            $this->assertSame((int) $downstream->id, (int) $connection->trading_partner_id);
            $this->assertNotSame((int) $legacy->id, (int) $connection->trading_partner_id);

            $this->assertSame(
                'urn:epc:id:sgln:0614141.00000.0',
                $downstream->fresh()->sgln,
                'The re-pointed partner must carry an SGLN so shipping can author destinationList.',
            );

            $this->assertFalse(
                (bool) $legacy->fresh()->is_active,
                'The legacy …0003 partner must be deactivated rather than left selectable.',
            );
        } finally {
            $this->cleanupLegacyPartner();
            tenancy()->end();
        }
    }

    /**
     * The seed only creates the demo wholesaler on an empty partner table, so a tenant
     * with real partners reaches the HQ step with …0001 absent. A `TradingPartner::first()`
     * fallback would then hand a seeded HQ site — and its GLN — to whichever real partner
     * that tenant happened to import first.
     */
    #[Test]
    public function seed_creates_no_partner_hq_site_when_the_demo_wholesaler_is_absent(): void
    {
        $this->initializeDemo2Tenant();

        $unrelatedId = null;
        $maskedWholesaler = null;
        $maskedGln = null;

        try {
            $unrelated = TradingPartner::query()->create([
                'name' => 'Imported Partner '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $unrelatedId = $unrelated->id;

            $maskedWholesaler = TradingPartner::query()->where('gln', self::WHOLESALER_GLN)->first();

            if ($maskedWholesaler !== null) {
                $maskedGln = $maskedWholesaler->gln;
                $maskedWholesaler->forceFill(['gln' => fake()->unique()->numerify('#############')])->save();

                // An earlier seed run against this shared tenant already gave the demo
                // wholesaler its HQ site. With that partner masked away the row is the
                // artifact this scenario is about, so it starts from absent.
                Site::query()
                    ->where('trading_partner_id', $maskedWholesaler->id)
                    ->where('is_headquarters', true)
                    ->delete();
            }

            $this->assertFalse(
                TradingPartner::query()->where('gln', self::WHOLESALER_GLN)->exists(),
                'This scenario requires the demo wholesaler to be absent.',
            );

            $firstPartner = TradingPartner::query()->orderBy('id')->first();
            $this->assertNotNull($firstPartner);
            $this->assertFalse(
                Site::query()
                    ->where('trading_partner_id', $firstPartner->id)
                    ->where('is_headquarters', true)
                    ->exists(),
                'The first partner already holds an HQ site, which a GLN-gated seed never creates. '
                    .'A seed that falls back to TradingPartner::first() leaves exactly this row behind.',
            );

            // Snapshot before the only seed run: seeding first would fold a wrongly
            // created HQ site into the baseline and hide the very regression under test.
            $partnerHqBefore = $this->partnerHeadquartersMap();

            app(SeedMasterData::class)->handle();

            $this->assertSame(
                $partnerHqBefore,
                $this->partnerHeadquartersMap(),
                'With the demo wholesaler absent the seed must create no partner HQ site.',
            );

            $this->assertFalse(
                Site::query()
                    ->where('trading_partner_id', $firstPartner->id)
                    ->where('is_headquarters', true)
                    ->exists(),
                'The first partner on the table must not be handed a seeded HQ site.',
            );

            $this->assertFalse(
                Site::query()->where('trading_partner_id', $unrelated->id)->exists(),
                'An imported partner must not receive a seeded HQ site.',
            );
        } finally {
            if ($maskedWholesaler !== null && $maskedGln !== null) {
                $maskedWholesaler->forceFill(['gln' => $maskedGln])->save();
            }

            if ($unrelatedId !== null) {
                Site::query()->where('trading_partner_id', $unrelatedId)->delete();
                TradingPartner::query()->whereKey($unrelatedId)->delete();
            }

            tenancy()->end();
        }
    }

    #[Test]
    public function seed_creates_the_hq_site_for_the_demo_wholesaler_when_present(): void
    {
        $this->initializeDemo2Tenant();

        $createdWholesalerId = null;

        try {
            app(SeedMasterData::class)->handle();

            $demoWholesaler = TradingPartner::query()->where('gln', self::WHOLESALER_GLN)->first();

            if ($demoWholesaler === null) {
                $demoWholesaler = TradingPartner::query()->create([
                    'name' => 'Demo Wholesaler',
                    'gln' => self::WHOLESALER_GLN,
                    'partner_type' => PartnerType::Wholesaler,
                    'street_address' => '100 Demo Street',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'zipcode' => '78701',
                    'country_code' => 'US',
                    'is_active' => true,
                ]);
                $createdWholesalerId = $demoWholesaler->id;
            }

            app(SeedMasterData::class)->handle();

            $this->assertTrue(
                Site::query()
                    ->where('trading_partner_id', $demoWholesaler->id)
                    ->where('is_headquarters', true)
                    ->exists(),
                'The demo wholesaler must have a seeded HQ site.',
            );
        } finally {
            if ($createdWholesalerId !== null) {
                Site::query()->where('trading_partner_id', $createdWholesalerId)->delete();
                TradingPartner::query()->whereKey($createdWholesalerId)->delete();
            }

            tenancy()->end();
        }
    }

    /**
     * @return list<int>
     */
    private function partnerHeadquartersMap(): array
    {
        return Site::query()
            ->whereNotNull('trading_partner_id')
            ->where('is_headquarters', true)
            ->orderBy('trading_partner_id')
            ->pluck('trading_partner_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function cleanupLegacyPartner(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $legacy = TradingPartner::query()->where('gln', self::LEGACY_GLN)->first();

        if ($legacy === null) {
            return;
        }

        Site::query()->where('trading_partner_id', $legacy->id)->delete();
        TradingPartner::query()->whereKey($legacy->id)->delete();
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
}
