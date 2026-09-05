<?php

namespace Tests\Feature\Packing;

use App\Actions\Receiving\UnpackReceivingHierarchy;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\UnpackWorkstation;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Epcis\EpcisCacheLock;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;
use DOMDocument;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnpackWorkstationTest extends TestCase
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
    private array $holdIds = [];

    private ?TenantProfile $priorProfile = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    #[Test]
    public function can_access_when_unpacking_supported_or_pharmacy_unpack_at_receive(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::BuyingGroup);
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsUnpacking());
            $this->assertFalse(UnpackWorkstation::canAccess());

            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->assertTrue(UnpackWorkstation::canAccess());

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsUnpacking());
            $this->assertTrue(UnpackWorkstation::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function partial_unpack_closes_only_selected_child_link(): void
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

            $options = app(UnpackReceivingHierarchy::class)->openChildOptionsForParent($parent);
            $this->assertArrayHasKey((int) $childA->getKey(), $options);
            $this->assertArrayHasKey((int) $childB->getKey(), $options);

            $result = app(UnpackReceivingHierarchy::class)->handleParent(
                $parent,
                [(int) $childA->getKey()],
                $site,
            );

            $this->assertTrue($result['generated']);
            $this->assertSame(1, $result['closed_links']);
            $document = $result['document'];
            $this->assertNotNull($document);
            $this->documentIds[] = (int) $document->getKey();

            $payload = Storage::disk((string) $document->payload_disk)->get((string) $document->payload_path);
            $this->assertIsString($payload);
            $this->assertStringContainsString('<baseExtension>', $payload);
            $this->assertEpcis12SchemaValid($payload);

            $this->assertFalse(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childA->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );
            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childB->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function handle_parent_requires_non_empty_children(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $parentUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $parent = Epc::query()->create(Epc::materializeAttributesFromUri($parentUri));
            $this->epcIds[] = (int) $parent->getKey();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Partial unpack requires at least one child EPC.');

            app(UnpackReceivingHierarchy::class)->handleParent($parent, []);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function workstation_scan_and_confirm_unpacks_selected_children(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            [$parent, $childA, $childB] = $this->seedOpenHierarchy($site);

            Livewire::test(UnpackWorkstation::class)
                ->set('scan', (string) $parent->epc_uri)
                ->call('processScan')
                ->assertSet('parentEpcId', (int) $parent->getKey())
                ->set('selectedChildIds', [(string) $childA->getKey()])
                ->callAction('confirmUnpack')
                ->assertSet('lastTone', 'ok');

            $this->assertFalse(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childA->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );
            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childB->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );

            $docs = EpcisDocument::query()
                ->where('notes', 'like', '%parent unpack%')
                ->orderByDesc('id')
                ->limit(3)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->documentIds = array_merge($this->documentIds, $docs);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function quarantined_child_is_hidden_from_options_and_cannot_be_unpacked(): void
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
            $this->openHold($childA);

            $options = app(UnpackReceivingHierarchy::class)->openChildOptionsForParent($parent);
            $this->assertArrayNotHasKey((int) $childA->getKey(), $options);
            $this->assertArrayHasKey((int) $childB->getKey(), $options);

            try {
                app(UnpackReceivingHierarchy::class)->handleParent(
                    $parent,
                    [(int) $childA->getKey()],
                    $site,
                );
                $this->fail('Expected a quarantined child to block the unpack.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Under quarantine', $exception->getMessage());
            }

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childA->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function stale_child_selection_is_rejected_before_unpacking(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            [$parent, $childA, $childB] = $this->seedOpenHierarchy($site);

            $component = Livewire::test(UnpackWorkstation::class)
                ->set('scan', (string) $parent->epc_uri)
                ->call('processScan')
                ->assertSet('parentEpcId', (int) $parent->getKey());

            // Another station closes the link after this station rendered its options.
            AggregationLink::query()
                ->where('parent_epc_id', $parent->getKey())
                ->where('child_epc_id', $childA->getKey())
                ->update(['valid_to' => now()]);

            $component
                ->set('selectedChildIds', [(string) $childA->getKey()])
                ->callAction('confirmUnpack')
                ->assertSet('lastTone', 'error')
                ->assertSet('lastMessage', 'Selected children are no longer open — rescan.');

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childB->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_unpack_requires_regulatory_password_when_gate_enabled(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => true]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            [$parent, $childA] = $this->seedOpenHierarchy($site);

            Livewire::test(UnpackWorkstation::class)
                ->set('scan', (string) $parent->epc_uri)
                ->call('processScan')
                ->set('selectedChildIds', [(string) $childA->getKey()])
                ->callAction('confirmUnpack', ['regulatory_password' => 'not-the-password'])
                ->assertHasActionErrors(['regulatory_password' => 'The password you entered is incorrect.']);

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childA->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'Unpack must not run without a valid regulatory password.',
            );

            Livewire::test(UnpackWorkstation::class)
                ->set('scan', (string) $parent->epc_uri)
                ->call('processScan')
                ->set('selectedChildIds', [(string) $childA->getKey()])
                ->callAction('confirmUnpack', ['regulatory_password' => 'password'])
                ->assertHasNoActionErrors()
                ->assertSet('lastTone', 'ok');

            $this->assertFalse(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childA->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );

            $docs = EpcisDocument::query()
                ->where('notes', 'like', '%parent unpack%')
                ->orderByDesc('id')
                ->limit(3)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->documentIds = array_merge($this->documentIds, $docs);
        } finally {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function unpack_rejects_when_child_lock_is_held(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            [$parent, $childA] = $this->seedOpenHierarchy($site);

            $held = EpcisCacheLock::lock('pack-child:'.$tenant->getKey().':'.$childA->getKey(), 30);
            $this->assertTrue($held->get());

            try {
                Livewire::test(UnpackWorkstation::class)
                    ->set('scan', (string) $parent->epc_uri)
                    ->call('processScan')
                    ->assertSet('parentEpcId', (int) $parent->getKey())
                    ->set('selectedChildIds', [(string) $childA->getKey()])
                    ->callAction('confirmUnpack')
                    ->assertSet('lastTone', 'error')
                    ->assertSet('lastMessage', 'Another pack is in progress for one of these children. Try again in a moment.');
            } finally {
                $held->release();
            }

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $childA->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'Unpack must not close links when a sibling workstation holds the child lock.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function operations_hub_unpacking_points_to_workstation(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $site = $this->createSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $hub = Livewire::test(OperationsHub::class)->instance();
            $unpacking = collect($hub->directories())->firstWhere('label', 'Unpacking');

            $this->assertNotNull($unpacking);
            $this->assertStringContainsString('unpack-workstation', (string) $unpacking['url']);
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
            'original_filename' => 'unpack-workstation-source.xml',
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

    /**
     * Unpacking requires tenant custody, which comes from a receiving event at one of
     * our GLNs — the same ObjectEvent GenerateReceivingEpcisEvents authors on receipt.
     */
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

    private function assertEpcis12SchemaValid(string $xml): void
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($xml), 'Unpack payload is not well-formed XML.');

        libxml_use_internal_errors(true);
        $valid = $document->schemaValidate(base_path('resources/xsd/epcis-1.2/EPCglobal-epcis-1_2.xsd'));
        $messages = array_map(
            static fn (\LibXMLError $error): string => trim($error->message),
            libxml_get_errors(),
        );
        libxml_clear_errors();

        $this->assertTrue($valid, 'Unpack payload failed EPCIS 1.2 XSD validation: '.implode(' | ', $messages));
    }

    private function openHold(Epc $epc): QuarantineHold
    {
        $hold = QuarantineHold::query()->create([
            'epc_id' => $epc->getKey(),
            'reason' => 'Unpack workstation test hold',
            'status' => 'open',
            'severity' => 'blocking',
            'opened_at' => now(),
        ]);
        $this->holdIds[] = (int) $hold->getKey();

        return $hold;
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
            'name' => 'Unpack Workstation Site',
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
        if ($this->holdIds !== []) {
            QuarantineHold::query()->whereIn('id', $this->holdIds)->delete();
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
