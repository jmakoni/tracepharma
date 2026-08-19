<?php

namespace Tests\Feature\Labeling;

use App\Actions\Labeling\BreakPalletAndReship;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccReshipMode;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Resources\SsccLabels\Pages\ListSsccLabels;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccSerialPool;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tracing\BuildAssetTrace;
use App\Support\Labeling\BreakPalletHierarchyOptions;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BreakPalletAndReshipFilamentTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $batchIds = [];

    /** @var list<int> */
    private array $labelIds = [];

    /** @var list<int> */
    private array $poolIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $linkIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $holdIds = [];

    private ?int $priorPoolLastSerial = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function break_pallet_and_reship_creates_one_label_per_child(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'received_at' => now(),
                'direction' => 'inbound',
                'status' => 'validated',
                'original_filename' => 'break-pallet-source.xml',
            ]);
            $this->documentIds[] = (int) $document->id;

            $childA = 'urn:epc:id:sgtin:030116.5200116.00000000413101';
            $childB = 'urn:epc:id:sgtin:030116.5200116.00000000413104';
            $parent = 'urn:epc:id:sscc:030116.00000210167';

            $this->seedOpenHierarchy($document, $parent, [$childA, $childB], $site);

            $batch = app(BreakPalletAndReship::class)->execute([
                'source_epcis_document_id' => $document->id,
                'source_parent_sscc_urn' => $parent,
                'selected_child_epcs' => [$childA, $childB],
                'reship_mode' => SsccReshipMode::PerChild->value,
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'copies_per_label' => 1,
                'site_id' => $site->id,
                'emit_epcis' => false,
                'emit_disaggregation' => false,
                'send_to_printer' => false,
            ]);

            $this->batchIds[] = (int) $batch->id;
            $this->labelIds = array_merge(
                $this->labelIds,
                $batch->labels->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );

            $this->assertSame(SsccLabelBatchStatus::Completed, $batch->status);
            $this->assertSame(2, $batch->label_count);
            $this->assertSame((int) $document->id, (int) $batch->source_epcis_document_id);
            $this->assertSame($parent, $batch->source_parent_sscc_urn);
            $this->assertSame(2, $batch->labels()->count());

            $batch->labels->each(function (SsccLabel $label) use ($childA, $childB): void {
                $this->assertSame(1, $label->children()->count());
                $child = (string) $label->children()->value('child_epc');
                $this->assertContains($child, [$childA, $childB]);
            });

            $this->trackCommissioningArtifacts($batch);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function break_and_pack_staggers_unpack_delete_before_pack_add(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'received_at' => now(),
                'direction' => 'inbound',
                'status' => 'validated',
                'original_filename' => 'break-pallet-stagger-source.xml',
            ]);
            $this->documentIds[] = (int) $document->id;

            $childA = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
            $parent = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);

            $this->seedOpenHierarchy($document, $parent, [$childA], $site);

            $batch = app(BreakPalletAndReship::class)->execute([
                'source_epcis_document_id' => $document->id,
                'source_parent_sscc_urn' => $parent,
                'selected_child_epcs' => [$childA],
                'reship_mode' => SsccReshipMode::Combined->value,
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'copies_per_label' => 1,
                'site_id' => $site->id,
                'send_to_printer' => false,
            ]);

            $this->batchIds[] = (int) $batch->id;
            $this->labelIds = array_merge(
                $this->labelIds,
                $batch->labels->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
            $this->trackCommissioningArtifacts($batch);

            $child = Epc::query()->where('epc_uri', $childA)->firstOrFail();

            $unpackEvent = EpcisEvent::query()
                ->where('event_type', 'AggregationEvent')
                ->where('action', 'DELETE')
                ->where(function ($q): void {
                    $q->where('biz_step', 'urn:epcglobal:cbv:bizstep:unpacking')
                        ->orWhere('biz_step', 'unpacking');
                })
                ->whereHas('epcs', fn ($q) => $q->where('epcs.id', $child->getKey()))
                ->orderByDesc('id')
                ->first();

            $packEvent = EpcisEvent::query()
                ->where('event_type', 'AggregationEvent')
                ->where('action', 'ADD')
                ->where(function ($q): void {
                    $q->where('biz_step', 'urn:epcglobal:cbv:bizstep:packing')
                        ->orWhere('biz_step', 'packing');
                })
                ->whereHas('epcs', fn ($q) => $q->where('epcs.id', $child->getKey()))
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($unpackEvent, 'Expected unpacking DELETE event');
            $this->assertNotNull($packEvent, 'Expected packing ADD event');
            $this->eventIds[] = (int) $unpackEvent->getKey();
            $this->eventIds[] = (int) $packEvent->getKey();

            $this->assertTrue(
                $unpackEvent->event_time->lt($packEvent->event_time),
                sprintf(
                    'Expected DELETE event_time (%s) before ADD (%s)',
                    $unpackEvent->event_time?->toIso8601String(),
                    $packEvent->event_time?->toIso8601String(),
                ),
            );

            $trace = app(BuildAssetTrace::class)->handle($childA);
            $this->assertTrue($trace['found']);

            $unpackStep = collect($trace['timeline'] ?? [])
                ->first(fn (array $step): bool => (int) ($step['id'] ?? 0) === (int) $unpackEvent->getKey());
            $packStep = collect($trace['timeline'] ?? [])
                ->first(fn (array $step): bool => (int) ($step['id'] ?? 0) === (int) $packEvent->getKey());

            $this->assertNotNull($unpackStep);
            $this->assertNotNull($packStep);
            $this->assertSame('DELETE', $unpackStep['action']);
            $this->assertSame('ADD', $packStep['action']);
            $this->assertLessThan(
                $packStep['event_time'] ?? '',
                $unpackStep['event_time'] ?? '',
            );

            $ordered = collect($trace['timeline'] ?? [])
                ->filter(fn (array $step): bool => in_array((int) ($step['id'] ?? 0), [
                    (int) $unpackEvent->getKey(),
                    (int) $packEvent->getKey(),
                ], true))
                ->sortBy([
                    function (array $a, array $b): int {
                        $aTime = filled($a['event_time'] ?? null)
                            ? (int) \Illuminate\Support\Carbon::parse($a['event_time'])->getTimestampMs()
                            : 0;
                        $bTime = filled($b['event_time'] ?? null)
                            ? (int) \Illuminate\Support\Carbon::parse($b['event_time'])->getTimestampMs()
                            : 0;

                        return $aTime <=> $bTime;
                    },
                    fn (array $a, array $b): int => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)),
                ])
                ->values();

            $this->assertCount(2, $ordered);
            $this->assertSame((int) $unpackEvent->getKey(), (int) $ordered[0]['id']);
            $this->assertSame((int) $packEvent->getKey(), (int) $ordered[1]['id']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function break_pallet_action_visible_when_packing_or_sscc_labeling_supported(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsSsccLabeling());
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsPacking());
            $this->assertTrue(SsccLabelResource::canAccess());
            $this->assertTrue(ListSsccLabels::canBreakPallet());

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsSsccLabeling());
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsPacking());
            $this->assertTrue(SsccLabelResource::canAccess());
            $this->assertTrue(ListSsccLabels::canBreakPallet());

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            Livewire::test(ListSsccLabels::class)
                ->assertActionVisible('breakPalletAndReship')
                ->assertActionVisible('create');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function operations_hub_directories_include_packing_and_unpacking_when_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $hub = Livewire::test(OperationsHub::class)->instance();
            $directories = collect($hub->directories());

            $packing = $directories->firstWhere('label', 'Packing');
            $this->assertNotNull($packing);
            $this->assertStringContainsString('pack-workstation', (string) $packing['url']);

            $unpacking = $directories->firstWhere('label', 'Unpacking');
            $this->assertNotNull($unpacking);
            $this->assertStringContainsString('unpack-workstation', (string) $unpacking['url']);

            $breakPack = $directories->firstWhere('label', 'Break & pack');
            $this->assertNotNull($breakPack);
            $this->assertStringContainsString('break-pack-workstation', (string) $breakPack['url']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function break_pallet_options_exclude_children_under_an_open_hold(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $document = $this->createSourceDocument();

            $childA = 'urn:epc:id:sgtin:030116.5200116.00000000413201';
            $childB = 'urn:epc:id:sgtin:030116.5200116.00000000413202';
            $parent = 'urn:epc:id:sscc:030116.00000210201';

            $this->seedOpenHierarchy($document, $parent, [$childA, $childB], $site);

            $this->assertArrayHasKey(
                $parent,
                BreakPalletHierarchyOptions::parentSsccOptions((int) $document->id),
            );

            $this->holdEpc($childB);

            $options = BreakPalletHierarchyOptions::childEpcOptions((int) $document->id, $parent);

            $this->assertArrayHasKey($childA, $options);
            $this->assertArrayNotHasKey($childB, $options);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function break_pallet_options_exclude_hierarchy_out_of_tenant_custody(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $document = $this->createSourceDocument();

            $child = 'urn:epc:id:sgtin:030116.5200116.00000000413301';
            $parent = 'urn:epc:id:sscc:030116.00000210301';

            // No receiving event at one of our GLNs: this pallet is a partner's, and merely
            // appears in our event store because they told us about it.
            $this->seedOpenHierarchy($document, $parent, [$child]);

            $this->assertSame([], BreakPalletHierarchyOptions::parentSsccOptions((int) $document->id));
            $this->assertSame([], BreakPalletHierarchyOptions::childEpcOptions((int) $document->id, $parent));
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createSourceDocument(): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'inbound',
            'status' => 'validated',
            'original_filename' => 'break-pallet-options.xml',
        ]);
        $this->documentIds[] = (int) $document->id;

        return $document;
    }

    private function holdEpc(string $epcUri): void
    {
        $epc = Epc::query()->where('epc_uri', $epcUri)->firstOrFail();

        $hold = QuarantineHold::query()->create([
            'epc_id' => $epc->getKey(),
            'reason' => 'Break pallet options test hold',
            'status' => 'open',
            'severity' => 'blocking',
            'opened_at' => now(),
        ]);
        $this->holdIds[] = (int) $hold->getKey();
    }

    private function seedOpenHierarchy(
        EpcisDocument $document,
        string $parentUrn,
        array $childUrns,
        ?Site $site = null,
    ): void {
        $parent = Epc::query()->create(Epc::materializeAttributesFromUri($parentUrn));
        $this->epcIds[] = (int) $parent->getKey();
        $received = [$parent];

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

        foreach ($childUrns as $childUrn) {
            $child = Epc::query()->create(Epc::materializeAttributesFromUri($childUrn));
            $this->epcIds[] = (int) $child->getKey();
            $received[] = $child;

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
            $this->receiveAtSite($site, $document, ...$received);
        }
    }

    /**
     * Breaking a pallet requires tenant custody, which comes from a receiving event at one
     * of our GLNs — the same ObjectEvent GenerateReceivingEpcisEvents authors on receipt.
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

    private function actingAsWithSiteAccess(Site $site): User
    {
        $user = User::factory()->create();
        $user->syncSites([(int) $site->id], (int) $site->id);
        $this->actingAs($user);

        return $user;
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

    private function prepareSerialPool(int $lastSerialReferenceInt): SsccSerialPool
    {
        $pool = SsccSerialPool::query()->firstOrNew([
            'company_prefix' => '0399991',
            'extension_digit' => '0',
        ]);

        if ($pool->exists && $this->priorPoolLastSerial === null) {
            $this->priorPoolLastSerial = (int) $pool->last_serial_reference_int;
        }

        $pool->fill([
            'default_allocation_mode' => SsccAllocationMode::Sequential,
            'last_serial_reference_int' => $lastSerialReferenceInt,
        ]);
        $pool->save();

        $this->poolIds[] = (int) $pool->id;

        return $pool;
    }

    private function createCommissionSite(Tenant $tenant, ?string $gln = null): Site
    {
        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorDefaultShipFromSiteId === null) {
            $this->priorDefaultShipFromSiteId = $settings->defaultShipFromSiteId();
        }

        // Site GLNs must live under the tenant's own GS1 company prefix (ResolveShipFromSite
        // asserts this), so derive the default from whatever prefix the test configured.
        $sitePrefix = $settings->companyPrefix() ?? '03';

        $site = Site::query()->create([
            'name' => 'Break Pallet Commission Site',
            'gln' => $gln ?? $this->uniqueSiteGln($sitePrefix),
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

    private function uniqueSiteGln(string $companyPrefix = '03'): string
    {
        $locationLen = 12 - strlen($companyPrefix);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $body = $companyPrefix.str_pad((string) random_int(0, (10 ** $locationLen) - 1), $locationLen, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach (str_split(strrev($body)) as $index => $digit) {
                $sum += ((int) $digit) * ($index % 2 === 0 ? 3 : 1);
            }
            $gln = $body.((string) ((10 - ($sum % 10)) % 10));

            if (! Site::query()->where('gln', $gln)->exists()) {
                return $gln;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique commission site GLN for the test.');
    }

    private function trackCommissioningArtifacts(SsccLabelBatch $batch): void
    {
        $documents = EpcisDocument::query()
            ->where('direction', 'outbound')
            ->where('notes', 'like', '%sscc_label_batch_id='.$batch->id.'%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->documentIds = array_values(array_unique(array_merge($this->documentIds, $documents)));

        $ssccs = $batch->labels()->pluck('sscc_18')->filter()->all();
        if ($ssccs !== []) {
            $epcIds = Epc::query()
                ->whereIn('sscc18', $ssccs)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->epcIds = array_values(array_unique(array_merge($this->epcIds, $epcIds)));
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

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

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
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }

        if ($this->epcIds !== []) {
            AggregationLink::query()
                ->whereIn('parent_epc_id', $this->epcIds)
                ->orWhereIn('child_epc_id', $this->epcIds)
                ->delete();
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
        }

        if ($this->priorPoolLastSerial !== null && $this->poolIds !== []) {
            SsccSerialPool::query()->whereIn('id', $this->poolIds)->update([
                'last_serial_reference_int' => $this->priorPoolLastSerial,
            ]);
        }

        if ($this->priorDefaultShipFromSiteId !== null) {
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
            $tenant->save();
        }

        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
        }

        tenancy()->end();
    }
}
