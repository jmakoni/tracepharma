<?php

namespace Tests\Feature\Shipping;

use App\Actions\Shipping\ResolveOutboundAuthoredLocation;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Shipping\ResolveShipFromSite;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveShipFromSiteTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SHIP_FROM_GLN = '0366159000096';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    private ?int $documentId = null;

    private ?int $eventId = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function default_ship_from_site_supplies_non_null_authored_event_glns(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->create([
                'name' => 'Ship-From DC '.Str::random(6),
                'gln' => self::SHIP_FROM_GLN,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $site->getKey());
            $tenant->save();

            $location = app(ResolveOutboundAuthoredLocation::class)->handle();

            $this->assertSame((int) $site->getKey(), $location['site_id']);
            $this->assertSame(self::SHIP_FROM_GLN, $location['gln']);
            $this->assertSame(self::SHIP_FROM_GLN, $location['read_point_gln']);
            $this->assertSame(self::SHIP_FROM_GLN, $location['biz_location_gln']);
            $this->assertNotNull($location['read_point_gln']);
            $this->assertNotNull($location['biz_location_gln']);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'authored-ship-from-test.xml',
                'payload_disk' => 'local',
                'payload_path' => 'epcis/outbound/ship-from-test.xml',
                'dscsa_affirm' => false,
                'status' => 'generated',
                'notes' => 'Test authored outbound shipping stub.',
                'reprocess_count' => 0,
                'event_count' => 1,
                'epc_count' => 0,
                'received_at' => now(),
                'ship_from_site_id' => $location['site_id'],
                'ship_from_gln' => $location['gln'],
            ]);
            $this->documentId = (int) $document->getKey();

            $event = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => 'urn:uuid:'.Str::uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'record_time' => now(),
                'event_timezone_offset' => '-05:00',
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
                'read_point_gln' => $location['read_point_gln'],
                'biz_location_gln' => $location['biz_location_gln'],
            ]);
            $this->eventId = (int) $event->getKey();

            $event->refresh();
            $this->assertSame(self::SHIP_FROM_GLN, $event->read_point_gln);
            $this->assertSame(self::SHIP_FROM_GLN, $event->biz_location_gln);
            $this->assertNotNull($event->read_point_gln);
            $this->assertNotNull($event->biz_location_gln);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function explicit_station_site_overrides_default_ship_from(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $default = Site::query()->create([
                'name' => 'Default Ship-From '.Str::random(6),
                'gln' => '0366159000010',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $station = Site::query()->create([
                'name' => 'Station Ship-From '.Str::random(6),
                'gln' => self::SHIP_FROM_GLN,
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $default->getKey();
            $this->siteIds[] = (int) $station->getKey();

            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $default->getKey());
            $tenant->save();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $resolved = app(ResolveShipFromSite::class)->handle((int) $station->getKey());

            $this->assertSame((int) $station->getKey(), $resolved);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function explicit_site_without_gln_throws_domain_exception(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->create([
                'name' => 'Ship-From No GLN '.Str::random(6),
                'gln' => null,
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('must have a 13-digit GLN before shipping');

            app(ResolveShipFromSite::class)->handle((int) $site->getKey());
        } finally {
            if (tenancy()->initialized) {
                $this->cleanup($tenant);
            }
        }
    }

    #[Test]
    public function missing_explicit_site_throws_not_found_domain_exception(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('was not found, is inactive, or is not an organization-owned site');

            app(ResolveShipFromSite::class)->handle(2_147_483_646);
        } finally {
            if (tenancy()->initialized) {
                $this->cleanup($tenant);
            }
        }
    }

    #[Test]
    public function machine_caller_rejects_explicit_site_that_does_not_match_default(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            auth()->logout();

            $default = Site::query()->create([
                'name' => 'Default Ship-From '.Str::random(6),
                'gln' => '0366159000010',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $other = Site::query()->create([
                'name' => 'Other Ship-From '.Str::random(6),
                'gln' => self::SHIP_FROM_GLN,
                'is_active' => true,
                'is_headquarters' => false,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $default->getKey();
            $this->siteIds[] = (int) $other->getKey();

            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $default->getKey());
            $tenant->save();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('must match the configured default ship-from site');

            app(ResolveShipFromSite::class)->handle((int) $other->getKey());
        } finally {
            if (tenancy()->initialized) {
                $this->cleanup($tenant);
            }
        }
    }

    #[Test]
    public function machine_caller_accepts_explicit_site_matching_default(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            auth()->logout();

            $default = Site::query()->create([
                'name' => 'Default Ship-From '.Str::random(6),
                'gln' => self::SHIP_FROM_GLN,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $default->getKey();

            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $default->getKey());
            $tenant->save();

            $resolved = app(ResolveShipFromSite::class)->handle((int) $default->getKey());

            $this->assertSame((int) $default->getKey(), $resolved);
        } finally {
            if (tenancy()->initialized) {
                $this->cleanup($tenant);
            }
        }
    }

    #[Test]
    public function machine_caller_rejects_explicit_site_when_no_default_is_configured(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            auth()->logout();

            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId(null);
            $tenant->save();

            $site = Site::query()->create([
                'name' => 'Ship-From No Default '.Str::random(6),
                'gln' => self::SHIP_FROM_GLN,
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('must omit site_id until a default ship-from site is configured');

            app(ResolveShipFromSite::class)->handle((int) $site->getKey());
        } finally {
            if (tenancy()->initialized) {
                $this->cleanup($tenant);
            }
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

        $this->priorDefaultShipFromSiteId = TenantSettings::forTenant($tenant)->defaultShipFromSiteId();
        $this->priorCompanyPrefix = TenantSettings::forTenant($tenant)->companyPrefix();

        // Ship-from site GLNs must be under the tenant's own GS1 company prefix; pin a prefix
        // consistent with SHIP_FROM_GLN regardless of leftover state from other test suites
        // that share this demo tenant fixture.
        $tenant->forceFill(['company_prefix' => '0366159'])->save();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->eventId !== null) {
                EpcisEvent::query()->whereKey($this->eventId)->delete();
                $this->eventId = null;
            }

            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $tenant->forceFill(['company_prefix' => $this->priorCompanyPrefix])->save();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
