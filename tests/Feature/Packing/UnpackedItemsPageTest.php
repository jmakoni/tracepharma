<?php

namespace Tests\Feature\Packing;

use App\Actions\Receiving\UnpackReceivingHierarchy;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\UnpackedItems;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Labeling\SsccBuilder;
use App\Support\Packing\UnpackedNotRepackedQuery;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnpackedItemsPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $linkIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $labelIds = [];

    /** @var list<int> */
    private array $batchIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function query_includes_unpack_released_loose_child(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            [$parent, $childA, $childB] = $this->seedOpenHierarchy($site);

            $result = app(UnpackReceivingHierarchy::class)->handleParent(
                $parent,
                [(int) $childA->getKey()],
                $site,
            );

            $this->assertTrue($result['generated']);
            $document = $result['document'];
            $this->assertNotNull($document);
            $this->documentIds[] = (int) $document->getKey();

            $ids = UnpackedNotRepackedQuery::builder()
                ->pluck('epcs.id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $childA->getKey(), $ids);
            $this->assertNotContains((int) $childB->getKey(), $ids);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function query_excludes_child_after_repack_open_parent_link(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            [$parent, $childA] = $this->seedOpenHierarchy($site);

            $result = app(UnpackReceivingHierarchy::class)->handleParent(
                $parent,
                [(int) $childA->getKey()],
                $site,
            );

            $this->assertTrue($result['generated']);
            $document = $result['document'];
            $this->assertNotNull($document);
            $this->documentIds[] = (int) $document->getKey();

            $newParentUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $newParent = Epc::query()->create(Epc::materializeAttributesFromUri($newParentUri));
            $this->epcIds[] = (int) $newParent->getKey();

            $repackDocument = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'received_at' => now(),
                'direction' => 'outbound',
                'status' => 'validated',
                'original_filename' => 'unpacked-items-repack.xml',
            ]);
            $this->documentIds[] = (int) $repackDocument->getKey();

            $repackEvent = EpcisEvent::query()->create([
                'document_id' => $repackDocument->getKey(),
                'event_id' => 'urn:uuid:'.(string) Str::uuid(),
                'event_type' => 'AggregationEvent',
                'event_time' => now(),
                'record_time' => now(),
                'event_timezone_offset' => '+00:00',
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            ]);
            $this->eventIds[] = (int) $repackEvent->getKey();

            $repackLink = AggregationLink::query()->create([
                'parent_epc_id' => $newParent->getKey(),
                'child_epc_id' => $childA->getKey(),
                'established_by_event_id' => $repackEvent->getKey(),
                'link_type' => 'aggregation',
                'valid_from' => now(),
                'valid_to' => null,
            ]);
            $this->linkIds[] = (int) $repackLink->getKey();

            $ids = UnpackedNotRepackedQuery::builder()
                ->pluck('epcs.id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertNotContains((int) $childA->getKey(), $ids);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function query_excludes_child_claimed_on_active_sscc_label(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            [$parent, $childA] = $this->seedOpenHierarchy($site);

            $result = app(UnpackReceivingHierarchy::class)->handleParent(
                $parent,
                [(int) $childA->getKey()],
                $site,
            );

            $this->assertTrue($result['generated']);
            $document = $result['document'];
            $this->assertNotNull($document);
            $this->documentIds[] = (int) $document->getKey();

            $built = app(SsccBuilder::class)->build('0399991', $this->uniqueSerialBase(), 0);

            $labelParent = Epc::query()->create(Epc::materializeAttributesFromUri($built['sscc_urn']));
            $this->epcIds[] = (int) $labelParent->getKey();

            $label = SsccLabel::query()->create([
                'batch_id' => null,
                'sscc_18' => $built['sscc_18'],
                'sscc_urn' => $built['sscc_urn'],
                'extension_digit' => $built['extension_digit'],
                'company_prefix' => $built['company_prefix'],
                'serial_reference' => $built['serial_reference'],
                'serial_reference_int' => $built['serial_reference_int'],
                'element_string' => $built['element_string'],
                'hrt' => $built['hrt'],
                'label_disk' => 'local',
                'label_path' => 'labels/sscc/unpacked-items-claim-test.pdf',
            ]);
            $this->labelIds[] = (int) $label->getKey();

            DB::table('sscc_label_children')->insert([
                'sscc_label_id' => $label->getKey(),
                'child_epc' => (string) $childA->epc_uri,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ids = UnpackedNotRepackedQuery::builder()
                ->pluck('epcs.id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertNotContains((int) $childA->getKey(), $ids);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function can_access_respects_feature_gates(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::BuyingGroup);
            $features = TenantFeatures::forTenant($tenant);
            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertFalse($features->supportsUnpacking());
            $this->assertFalse($features->supportsPacking());
            $this->assertFalse($policy->canUnpackAtReceive());
            $this->assertFalse(UnpackedItems::canAccess());

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsUnpacking());
            $this->assertTrue(UnpackedItems::canAccess());

            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->assertTrue(ReceivingPolicy::forTenant($tenant)->canUnpackAtReceive());
            $this->assertTrue(UnpackedItems::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function page_renders_for_authorized_user(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            Livewire::test(UnpackedItems::class)->assertSuccessful();

            $hub = Livewire::test(OperationsHub::class)->instance();
            $unpackedItems = collect($hub->directories())->firstWhere('label', 'Unpacked items');

            $this->assertNotNull($unpackedItems);
            $this->assertStringContainsString('unpacked-items', (string) $unpackedItems['url']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: Epc, 1: Epc, 2: Epc}
     */
    private function seedOpenHierarchy(?Site $site = null): array
    {
        $parentUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        $childAUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
        $childBUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(80000000000000, 89999999999999);

        $parent = Epc::query()->create(Epc::materializeAttributesFromUri($parentUri));
        $childA = Epc::query()->create(Epc::materializeAttributesFromUri($childAUri));
        $childB = Epc::query()->create(Epc::materializeAttributesFromUri($childBUri));

        $this->epcIds = array_merge($this->epcIds, [
            (int) $parent->getKey(),
            (int) $childA->getKey(),
            (int) $childB->getKey(),
        ]);

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'inbound',
            'status' => 'validated',
            'original_filename' => 'unpacked-items-source.xml',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'AggregationEvent',
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'ADD',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
        ]);
        $this->eventIds[] = (int) $event->getKey();

        foreach ([$childA, $childB] as $child) {
            $link = AggregationLink::query()->create([
                'parent_epc_id' => $parent->getKey(),
                'child_epc_id' => $child->getKey(),
                'established_by_event_id' => $event->getKey(),
                'link_type' => 'aggregation',
                'valid_from' => now(),
                'valid_to' => null,
            ]);
            $this->linkIds[] = (int) $link->getKey();
        }

        if ($site !== null) {
            $this->receiveAtSite($site, $document, $parent, $childA, $childB);
        }

        return [$parent, $childA, $childB];
    }

    private function receiveAtSite(Site $site, EpcisDocument $document, Epc ...$epcs): void
    {
        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore(array_map(
            fn (Epc $epc): array => [
                'event_id' => $event->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ],
            $epcs,
        ));
    }

    private function uniqueSerialBase(): int
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $base = random_int(800000, 900000);

            $collision = SsccLabel::query()
                ->where('company_prefix', '0399991')
                ->where('extension_digit', '0')
                ->whereBetween('serial_reference_int', [$base + 1, $base + 5])
                ->exists();

            if (! $collision) {
                return $base;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique SSCC serial base for the test.');
    }

    private function actingAsWithSiteAccess(Site $site): User
    {
        $user = User::factory()->create();
        $user->syncSites([(int) $site->id], (int) $site->id);
        $this->actingAs($user);

        return $user;
    }

    private function createSite(Tenant $tenant): Site
    {
        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }

        $site = Site::query()->create([
            'name' => 'Unpacked Items Site',
            'gln' => $this->uniqueSiteGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->id;
        $settings->setDefaultShipFromSiteId((int) $site->id);
        $tenant->save();

        return $site;
    }

    private function uniqueSiteGln(): string
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split(strrev($body)) as $index => $digit) {
                $sum += ((int) $digit) * ($index % 2 === 0 ? 3 : 1);
            }
            $gln = $body.((string) ((10 - ($sum % 10)) % 10));

            if (! Site::query()->where('gln', $gln)->exists()) {
                return $gln;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique site GLN.');
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

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);
        $this->priorGln = $tenant->gln;
        $this->priorCompanyPrefix = $tenant->company_prefix;

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

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        $tenant->forceFill(['profile' => $profile])->save();
        $tenant->refresh();
    }

    private function cleanup(Tenant $tenant): void
    {
        if ($this->labelIds !== []) {
            DB::table('sscc_label_children')->whereIn('sscc_label_id', $this->labelIds)->delete();
            SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
        }

        if ($this->batchIds !== []) {
            SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
        }

        if ($this->linkIds !== []) {
            AggregationLink::query()->whereIn('id', $this->linkIds)->delete();
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
        }

        if ($this->documentIds !== []) {
            $eventIds = EpcisEvent::query()
                ->whereIn('document_id', $this->documentIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if ($eventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                EpcisEvent::query()->whereIn('id', $eventIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }

        if ($this->epcIds !== []) {
            AggregationLink::query()
                ->whereIn('parent_epc_id', $this->epcIds)
                ->orWhereIn('child_epc_id', $this->epcIds)
                ->delete();
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            if (DB::getSchemaBuilder()->hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            Epc::query()->whereIn('id', $this->epcIds)->delete();
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }

        if ($this->priorDefaultShipFromSiteId !== null) {
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $tenant->save();
        }

        // Restored directly: saveOrganization() re-validates and would reject the demo baseline.
        $tenant->forceFill([
            'gln' => $this->priorGln,
            'company_prefix' => $this->priorCompanyPrefix,
        ])->save();

        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
        }

        tenancy()->end();
    }
}
