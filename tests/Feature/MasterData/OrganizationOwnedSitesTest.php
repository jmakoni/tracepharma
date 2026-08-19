<?php

namespace Tests\Feature\MasterData;

use App\Actions\Demo\SeedMasterData;
use App\Actions\Epcis\EnsureCatalogPartiesFromEpcisLocations;
use App\Actions\MasterData\PromoteUnassignedSitesToOwned;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ResolveReceivingSite;
use App\Support\Shipping\ResolveShipFromSite;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationOwnedSitesTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    private ?int $documentId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    /** @var list<string> */
    private array $cleanupGlns = [];

    #[Test]
    public function eligible_receive_sites_exclude_partner_linked_sites(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $owned = Site::factory()->owned()->create([
                'name' => 'Owned Receive '.Str::random(4),
                'code' => 'RCV-'.Str::random(4),
                'gln' => '0366159011001',
                'is_active' => true,
            ]);
            $this->assertTrue($owned->is_organization_facility);
            $this->assertNull($owned->trading_partner_id);

            $partner = TradingPartner::factory()->create([
                'name' => 'Partner For Site '.Str::random(4),
                'partner_type' => PartnerType::Wholesaler,
            ]);
            $partnerSite = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Partner Receive '.Str::random(4),
                'gln' => '0366159011002',
                'is_active' => true,
            ]);
            $falseOwnedHq = Site::query()->create([
                'trading_partner_id' => null,
                'name' => 'Catalog Labeler Junk - HQ Site',
                'gln' => '0366159011008',
                'is_active' => true,
                'is_organization_facility' => false,
                'country_code' => 'US',
            ]);
            $this->siteIds = [(int) $owned->id, (int) $partnerSite->id, (int) $falseOwnedHq->id];
            $this->partnerIds = [(int) $partner->id];

            $options = EligibleReceiveSites::forOrganization()
                ->get(['id', 'name', 'gln'])
                ->mapWithKeys(fn (Site $site): array => [
                    (int) $site->getKey() => $site->name.' ('.$site->gln.')',
                ])
                ->all();

            $this->assertArrayHasKey((int) $owned->id, $options);
            $this->assertArrayNotHasKey((int) $partnerSite->id, $options);
            $this->assertArrayNotHasKey((int) $falseOwnedHq->id, $options);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function eligible_receive_sites_exclude_test_coded_organization_facilities(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $testSite = Site::factory()->owned()->create([
                'name' => 'Eligible Filter Test '.Str::random(4),
                'gln' => '0366159011010',
                'is_active' => true,
            ]);
            $this->siteIds = [(int) $testSite->id];

            $this->assertStringStartsWith('TEST-', (string) $testSite->code);

            $options = EligibleReceiveSites::options();

            $this->assertArrayNotHasKey((int) $testSite->id, $options);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function edit_sync_attaching_partner_clears_organization_facility_and_eligibility(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Site::organizationFacilityFlagFromPartnerId(null));
            $this->assertFalse(Site::organizationFacilityFlagFromPartnerId(1));

            $owned = Site::factory()->owned()->create([
                'name' => 'Edit Sync Own '.Str::random(4),
                'code' => 'EDT-'.Str::random(4),
                'gln' => '0366159011009',
                'is_active' => true,
            ]);
            $partner = TradingPartner::factory()->create([
                'name' => 'Edit Sync Partner '.Str::random(4),
                'partner_type' => PartnerType::Wholesaler,
            ]);
            $this->siteIds = [(int) $owned->id];
            $this->partnerIds = [(int) $partner->id];

            $this->assertArrayHasKey(
                (int) $owned->id,
                EligibleReceiveSites::forOrganization()
                    ->get(['id'])
                    ->mapWithKeys(fn (Site $site): array => [(int) $site->getKey() => true])
                    ->all(),
            );

            $owned->update(Site::syncOrganizationFacilityFlag([
                'trading_partner_id' => $partner->id,
            ]));
            $owned->refresh();

            $this->assertFalse((bool) $owned->is_organization_facility);
            $this->assertSame((int) $partner->id, (int) $owned->trading_partner_id);
            $this->assertArrayNotHasKey(
                (int) $owned->id,
                EligibleReceiveSites::forOrganization()
                    ->get(['id'])
                    ->mapWithKeys(fn (Site $site): array => [(int) $site->getKey() => true])
                    ->all(),
            );

            $owned->update(Site::syncOrganizationFacilityFlag([
                'trading_partner_id' => null,
            ]));
            $owned->refresh();

            $this->assertTrue((bool) $owned->is_organization_facility);
            $this->assertNull($owned->trading_partner_id);
            $this->assertArrayHasKey(
                (int) $owned->id,
                EligibleReceiveSites::forOrganization()
                    ->get(['id'])
                    ->mapWithKeys(fn (Site $site): array => [(int) $site->getKey() => true])
                    ->all(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function resolve_receiving_site_skips_partner_ship_to_and_uses_owned_default(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $owned = Site::factory()->owned()->create([
                'name' => 'Owned HQ '.Str::random(4),
                'code' => 'HQ-'.Str::random(4),
                'gln' => '0366159011003',
                'is_active' => true,
                'is_headquarters' => true,
            ]);
            $partner = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Wholesaler,
            ]);
            $partnerSite = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Partner Ship-To '.Str::random(4),
                'gln' => '0366159011004',
                'is_active' => true,
            ]);
            $this->siteIds = [(int) $owned->id, (int) $partnerSite->id];
            $this->partnerIds = [(int) $partner->id];

            TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId((int) $owned->id);
            $tenant->save();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'owned-sites-ship-to.xml',
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/owned-sites-ship-to.xml',
                'dscsa_affirm' => false,
                'status' => 'ready',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
                'ship_to_site_id' => $partnerSite->id,
            ]);
            $this->documentId = (int) $document->id;

            $resolved = app(ResolveReceivingSite::class)->handle($document);

            $this->assertSame((int) $owned->id, $resolved);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function resolve_ship_from_rejects_partner_linked_explicit_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $partner = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Wholesaler,
            ]);
            $partnerSite = Site::factory()->create([
                'trading_partner_id' => $partner->id,
                'name' => 'Partner Ship-From '.Str::random(4),
                'gln' => '0366159011005',
                'is_active' => true,
            ]);
            $this->siteIds = [(int) $partnerSite->id];
            $this->partnerIds = [(int) $partner->id];

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('organization-owned site');

            app(ResolveShipFromSite::class)->handle((int) $partnerSite->id);
        } finally {
            if (tenancy()->initialized) {
                $this->cleanup($tenant);
            }
        }
    }

    #[Test]
    public function promote_unassigned_deactivates_sites_without_making_them_owned(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $unassigned = TradingPartner::query()->create([
                'name' => 'Unassigned',
                'partner_type' => PartnerType::Other,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $site = Site::query()->create([
                'trading_partner_id' => $unassigned->id,
                'name' => 'Was Unassigned '.Str::random(4),
                'gln' => '0366159011006',
                'is_active' => true,
                'is_organization_facility' => false,
                'country_code' => 'US',
            ]);
            $this->siteIds = [(int) $site->id];
            $this->partnerIds = [(int) $unassigned->id];

            $partnerDefault = TradingPartner::factory()->create([
                'partner_type' => PartnerType::Wholesaler,
            ]);
            $partnerSite = Site::factory()->create([
                'trading_partner_id' => $partnerDefault->id,
                'gln' => '0366159011007',
                'is_active' => true,
            ]);
            $this->siteIds[] = (int) $partnerSite->id;
            $this->partnerIds[] = (int) $partnerDefault->id;

            TenantSettings::forTenant($tenant)->setDefaultReceiveSiteId((int) $partnerSite->id);
            $tenant->save();

            $result = app(PromoteUnassignedSitesToOwned::class)->handle();

            $this->assertSame(1, $result['sites_deactivated']);
            $this->assertFalse($result['unassigned_deleted']);
            $this->assertGreaterThanOrEqual(1, $result['defaults_cleared']);

            $site->refresh();
            $this->assertSame((int) $unassigned->id, (int) $site->trading_partner_id);
            $this->assertFalse((bool) $site->is_active);
            $this->assertFalse((bool) $site->is_organization_facility);
            $this->assertNotNull(TradingPartner::query()->where('name', 'Unassigned')->value('id'));
            $options = EligibleReceiveSites::forOrganization()
                ->get(['id', 'name', 'gln'])
                ->mapWithKeys(fn (Site $site): array => [
                    (int) $site->getKey() => $site->name.' ('.$site->gln.')',
                ])
                ->all();
            $this->assertArrayNotHasKey((int) $site->id, $options);
            $this->assertNull(TenantSettings::forTenant($tenant->fresh())->defaultReceiveSiteId());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ensure_catalog_parties_preserves_owned_null_trading_partner_id(): void
    {
        $suffix = (string) random_int(10000, 99999);
        $destOwningGln = '03661590'.$suffix;
        $destLocationGln = '03661591'.$suffix;
        $this->cleanupGlns = [$destOwningGln, $destLocationGln];

        $tenant = $this->initializeDemo2Tenant();

        try {
            $owned = Site::factory()->owned()->create([
                'name' => 'Owned Dest '.$suffix,
                'code' => 'DST-'.$suffix,
                'gln' => $destLocationGln,
                'is_active' => true,
                'street_address' => null,
            ]);
            $this->siteIds = [(int) $owned->id];

            $locations = [
                [
                    'gln' => $destOwningGln,
                    'name' => 'Dest Owner '.$suffix,
                    'country_code' => 'US',
                ],
                [
                    'gln' => $destLocationGln,
                    'name' => 'Dest Location '.$suffix,
                    'street_address' => '99 Filled St',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'postal_code' => '78701',
                    'country_code' => 'US',
                ],
            ];

            app(EnsureCatalogPartiesFromEpcisLocations::class)->handle($locations, [
                'destination_owning_party_gln' => $destOwningGln,
                'destination_location_gln' => $destLocationGln,
            ]);

            $owned->refresh();
            $this->assertNull($owned->trading_partner_id);
            $this->assertSame('99 Filled St', $owned->street_address);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function seed_master_data_ensures_owned_organization_hq_for_defaults(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $orgGln = '8765432109876';
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => $orgGln,
                'company_prefix' => '8765432',
                'street_address' => null,
                'street_address_2' => null,
                'city' => null,
                'state' => null,
                'zipcode' => null,
                'country_code' => null,
            ]);

            app(SeedMasterData::class)->handle();

            $settings = TenantSettings::forTenant($tenant->fresh());
            $this->assertTrue($settings->hasOrganizationAddress());
            $this->assertSame('IL', $settings->state());

            $ownedHq = Site::query()
                ->whereNull('trading_partner_id')
                ->where('code', 'ORG-HQ')
                ->first();

            $this->assertNotNull($ownedHq);
            $this->assertTrue((bool) $ownedHq->is_headquarters);
            $this->assertTrue((bool) $ownedHq->is_organization_facility);
            $this->assertSame($orgGln, $ownedHq->gln);
            $this->assertSame('IL', $ownedHq->state);
            $this->assertSame($settings->streetAddress(), $ownedHq->street_address);
            $this->assertSame($settings->city(), $ownedHq->city);
            $this->assertSame((int) $ownedHq->id, $settings->defaultReceiveSiteId());
            $this->assertSame((int) $ownedHq->id, $settings->defaultShipFromSiteId());
        } finally {
            $this->cleanup($tenant);
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

        $settings = TenantSettings::forTenant($tenant);
        $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            if ($this->cleanupGlns !== []) {
                Site::query()->whereIn('gln', $this->cleanupGlns)->delete();
                TradingPartner::query()->whereIn('gln', $this->cleanupGlns)->delete();
                $this->cleanupGlns = [];
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
                TradingPartner::query()->where('name', 'Unassigned')->delete();
                $this->partnerIds = [];
            }

            TenantSettings::forTenant($tenant)
                ->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId)
                ->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $tenant->save();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
