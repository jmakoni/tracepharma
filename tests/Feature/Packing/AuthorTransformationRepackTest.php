<?php

declare(strict_types=1);

namespace Tests\Feature\Packing;

use App\Actions\Packing\AuthorTransformationRepack;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\RepackTransformWorkstation;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tracing\BuildAssetTrace;
use App\Support\Gs1\Gtin;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthorTransformationRepackTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?int $priorDefaultReceiveSiteId = null;

    private bool $capturedOrganization = false;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function prepackager_authors_transformation_event_with_input_and_output_roles(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Prepackager);
            $this->configureOrganization($tenant);
            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $input = $this->createEpc('IN');
            $this->receiveAtSite($site, $input);

            $outputUri = 'urn:epc:id:sgtin:0399991.000001.'.(string) random_int(100000000, 999999999);

            $result = app(AuthorTransformationRepack::class)->handle(
                siteId: (int) $site->getKey(),
                inputEpcIds: [(int) $input->getKey()],
                outputUris: [$outputUri],
                options: ['sync' => true, 'dispatch' => true],
            );

            $this->assertNotNull($result['document']);
            $this->documentIds[] = (int) $result['document']->getKey();
            $this->assertNotEmpty($result['transformation_id']);
            $this->assertSame(1, $result['input_count']);
            $this->assertSame(1, $result['output_count']);

            $event = EpcisEvent::query()
                ->where('document_id', $result['document']->getKey())
                ->where('event_type', 'TransformationEvent')
                ->first();
            $this->assertNotNull($event);
            $this->eventIds[] = (int) $event->getKey();
            $this->assertSame(
                $result['transformation_id'],
                $event->extension_json['transformation_id'] ?? null,
            );

            $roles = DB::table('event_epcs')
                ->where('event_id', $event->getKey())
                ->pluck('role', 'epc_id');

            $this->assertSame('inputEPC', $roles[(int) $input->getKey()] ?? null);

            $output = Epc::query()->where('epc_uri', $outputUri)->first();
            $this->assertNotNull($output);
            $this->epcIds[] = (int) $output->getKey();
            $this->assertSame('outputEPC', $roles[(int) $output->getKey()] ?? null);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function asset_trace_for_output_shows_linked_input(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Prepackager);
            $this->configureOrganization($tenant);
            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $input = $this->createEpc('IN');
            $this->receiveAtSite($site, $input);

            $outputUri = 'urn:epc:id:sgtin:0399991.000001.'.(string) random_int(100000000, 999999999);

            $result = app(AuthorTransformationRepack::class)->handle(
                siteId: (int) $site->getKey(),
                inputEpcIds: [(int) $input->getKey()],
                outputUris: [$outputUri],
                options: ['sync' => true, 'dispatch' => true],
            );

            $this->documentIds[] = (int) $result['document']->getKey();
            $output = Epc::query()->where('epc_uri', $outputUri)->first();
            $this->assertNotNull($output);
            $this->epcIds[] = (int) $output->getKey();

            $trace = app(BuildAssetTrace::class)->handle((string) $output->epc_uri);

            $this->assertTrue($trace['found']);
            $this->assertArrayHasKey('transformation_links', $trace);
            $this->assertNotEmpty($trace['transformation_links']);

            $link = $trace['transformation_links'][0];
            $this->assertSame('outputEPC', $link['role']);
            $this->assertSame('inputEPC', $link['counterpart_role']);
            $this->assertSame((int) $input->getKey(), $link['counterpart_epc_id']);
            $this->assertSame((string) $input->epc_uri, $link['counterpart_urn']);
            $this->assertSame($result['transformation_id'], $link['transformation_id']);

            $inputTrace = app(BuildAssetTrace::class)->handle((string) $input->epc_uri);
            $this->assertNotEmpty($inputTrace['transformation_links']);
            $this->assertSame('inputEPC', $inputTrace['transformation_links'][0]['role']);
            $this->assertSame((int) $output->getKey(), $inputTrace['transformation_links'][0]['counterpart_epc_id']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pharmacy_and_wholesaler_cannot_access_repack_transform_page(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = User::factory()->create();
            $this->actingAs($user);

            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->assertFalse(TenantFeatures::forTenant($tenant->fresh())->supportsRepackTransform());
            $this->assertFalse(RepackTransformWorkstation::canAccess());

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->assertFalse(TenantFeatures::forTenant($tenant->fresh())->supportsRepackTransform());
            $this->assertFalse(RepackTransformWorkstation::canAccess());

            $this->setProfile($tenant, TenantProfile::Prepackager);
            $this->assertTrue(TenantFeatures::forTenant($tenant->fresh())->supportsRepackTransform());
            $this->assertTrue(RepackTransformWorkstation::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        if ($this->priorProfile === null) {
            $this->priorProfile = $tenant->profile instanceof TenantProfile
                ? $tenant->profile
                : TenantProfile::tryFrom((string) $tenant->profile) ?? TenantProfile::Pharmacy;
        }

        $tenant->forceFill(['profile' => $profile])->save();
        $tenant->refresh();
    }

    private function actingAsWithSiteAccess(Site $site): User
    {
        $user = User::factory()->create();
        $user->syncSites([(int) $site->id], (int) $site->id);
        $this->actingAs($user);

        return $user;
    }

    private function configureOrganization(Tenant $tenant): void
    {
        $settings = TenantSettings::forTenant($tenant);
        if (! $this->capturedOrganization) {
            $this->priorGln = $settings->gln();
            $this->priorCompanyPrefix = $settings->companyPrefix();
            $this->capturedOrganization = true;
        }

        $settings->setGln('0399991000001');
        $settings->setCompanyPrefix('0399991');
        $tenant->save();
    }

    private function createSite(Tenant $tenant): Site
    {
        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
            $this->priorDefaultReceiveSiteId = $settings->defaultReceiveSiteId();
        }

        $site = Site::query()->create([
            'name' => 'Repack Site '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();
        $settings->setDefaultShipFromSiteId((int) $site->getKey());
        $settings->setDefaultReceiveSiteId((int) $site->getKey());
        $tenant->save();

        return $site;
    }

    private function createEpc(string $tag = 'T'): Epc
    {
        $serial = $tag.(string) random_int(10000000, 99999999);
        $uri = 'urn:epc:id:sgtin:0399991.000001.'.$serial;

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function receiveAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'repack-seed-'.Str::random(6).'.xml',
            'notes' => 'Seeded receiving for repack transform test.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinute(),
            'record_time' => now()->subMinute(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function uniqueGln(): string
    {
        $prefix = TenantSettings::forTenant(tenant())->companyPrefix() ?: '0399991';
        $fill = max(1, 12 - strlen($prefix));

        do {
            $body = substr($prefix.str_pad((string) random_int(0, (int) str_repeat('9', $fill)), $fill, '0', STR_PAD_LEFT), 0, 12);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }

        if ($this->epcIds !== []) {
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            if (DB::getSchemaBuilder()->hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('epc_ilmd')) {
                DB::table('epc_ilmd')->whereIn('epc_id', $this->epcIds)->delete();
            }
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->documentIds !== []) {
            $eventIds = EpcisEvent::query()->whereIn('document_id', $this->documentIds)->pluck('id');
            if ($eventIds->isNotEmpty()) {
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                EpcisEvent::query()->whereIn('id', $eventIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorDefaultShipFromSiteId !== null) {
            $settings->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $settings->setDefaultReceiveSiteId($this->priorDefaultReceiveSiteId);
        }
        if ($this->capturedOrganization) {
            $settings->setGln($this->priorGln);
            $settings->setCompanyPrefix($this->priorCompanyPrefix);
        }
        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
        }
        $tenant->save();

        $this->priorProfile = null;
        $this->priorDefaultShipFromSiteId = null;
        $this->priorDefaultReceiveSiteId = null;
        $this->capturedOrganization = false;
        $this->priorGln = null;
        $this->priorCompanyPrefix = null;

        tenancy()->end();
    }
}
