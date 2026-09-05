<?php

namespace Tests\Feature\Packing;

use App\Actions\Labeling\GenerateSsccLabelBatch;
use App\Actions\Receiving\UnpackReceivingHierarchy;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\ValidateOutboundShippingSend;
use App\Actions\Transferring\ConfirmTransferringScan;
use App\Actions\Transferring\OpenTransferringSession;
use App\Enums\EpcisAuthoredKind;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\BreakPackWorkstation;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\PackWorkstation;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccSerialPool;
use App\Models\Tenant;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Services\Labeling\SsccBuilder;
use App\Support\Epcis\EpcisCacheLock;
use App\Support\Shipping\AssertOutermostSsccHasChildren;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PackWorkstationTest extends TestCase
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
    private array $eventIds = [];

    /** @var list<int> */
    private array $linkIds = [];

    /** @var list<int> */
    private array $shippingSessionIds = [];

    /** @var list<int> */
    private array $transferSessionIds = [];

    private ?int $priorPoolLastSerial = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function access_allowed_for_pharmacy_and_wholesaler(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsPacking());
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsSsccLabeling());
            $this->assertTrue(PackWorkstation::canAccess());
            $this->assertTrue(BreakPackWorkstation::canAccess());

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->assertTrue(PackWorkstation::canAccess());
            $this->assertTrue(BreakPackWorkstation::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_pack_action_requires_confirmation_and_shows_tenant_gcp(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $childUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
            $child = Epc::query()->firstOrCreate(
                ['epc_uri' => $childUri],
                Epc::materializeAttributesFromUri($childUri),
            );
            $this->epcIds[] = (int) $child->getKey();
            $this->receiveAtSite($site, $child);

            $component = Livewire::test(PackWorkstation::class)
                ->set('scan', $childUri)
                ->call('processScan')
                ->assertCount('children', 1);

            $description = $component->instance()->confirmPackAction()->getModalDescription();
            if ($description instanceof \Closure) {
                $description = $description();
            }

            $this->assertIsString($description);
            $this->assertStringContainsString('0399991', $description);
            $this->assertStringContainsString('Commission new parent SSCC', (string) $component->instance()->confirmPackAction()->getModalHeading());
            $this->assertTrue($component->instance()->confirmPackAction()->isConfirmationRequired());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pack_workstation_creates_sscc_batch_from_scanned_children(): void
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

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $childUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
            $child = Epc::query()->firstOrCreate(
                ['epc_uri' => $childUri],
                Epc::materializeAttributesFromUri($childUri),
            );
            $this->epcIds[] = (int) $child->getKey();
            $this->receiveAtSite($site, $child);

            $response = Livewire::test(PackWorkstation::class)
                ->set('scan', $childUri)
                ->call('processScan')
                ->assertCount('children', 1)
                ->callAction('confirmPack');

            $batch = SsccLabelBatch::query()->orderByDesc('id')->first();
            $this->assertNotNull($batch);
            $this->batchIds[] = (int) $batch->id;
            $this->labelIds = array_merge(
                $this->labelIds,
                $batch->labels->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );

            $this->assertSame(SsccLabelBatchStatus::Completed, $batch->status);
            $this->assertSame(1, $batch->labels()->count());
            $this->assertSame('0399991', (string) $batch->company_prefix);
            $this->assertSame('0399991', (string) $batch->labels()->first()?->company_prefix);
            $this->assertSame(1, $batch->labels()->first()?->children()->count());

            $response->assertRedirect();

            $this->trackCommissioningArtifacts($batch);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pack_workstation_rejects_child_with_open_parent_link(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $parentUri = 'urn:epc:id:sscc:0399991.0'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
            $childUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);

            $parent = Epc::query()->create(Epc::materializeAttributesFromUri($parentUri));
            $child = Epc::query()->create(Epc::materializeAttributesFromUri($childUri));
            $this->epcIds[] = (int) $parent->getKey();
            $this->epcIds[] = (int) $child->getKey();
            $this->receiveAtSite($site, $parent, $child);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'received_at' => now(),
                'direction' => 'inbound',
                'status' => 'validated',
                'original_filename' => 'pack-reject-open-link.xml',
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

            $link = AggregationLink::query()->create([
                'parent_epc_id' => $parent->getKey(),
                'child_epc_id' => $child->getKey(),
                'established_by_event_id' => $event->getKey(),
                'link_type' => 'aggregation',
                'valid_from' => now()->subMinute(),
                'valid_to' => null,
            ]);

            $component = Livewire::test(PackWorkstation::class)
                ->set('scan', $childUri)
                ->call('processScan')
                ->assertCount('children', 0)
                ->assertSet('lastTone', 'warn');

            $this->assertStringContainsString('Already packed under', (string) $component->get('lastMessage'));

            $link->delete();
            $event->delete();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pack_workstation_rejects_child_already_attached_to_live_label(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $childUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
            $child = Epc::query()->create(Epc::materializeAttributesFromUri($childUri));
            $this->epcIds[] = (int) $child->getKey();
            $this->receiveAtSite($site, $child);

            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => '0399991',
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 1,
                'status' => SsccLabelBatchStatus::Completed,
            ]);
            $this->batchIds[] = (int) $batch->getKey();

            $built = app(SsccBuilder::class)->build('0399991', $this->uniqueSerialBase(), 0);

            $label = SsccLabel::query()->create([
                'batch_id' => $batch->getKey(),
                'sscc_18' => $built['sscc_18'],
                'sscc_urn' => $built['sscc_urn'],
                'extension_digit' => $built['extension_digit'],
                'company_prefix' => $built['company_prefix'],
                'serial_reference' => $built['serial_reference'],
                'serial_reference_int' => $built['serial_reference_int'],
                'element_string' => $built['element_string'],
                'hrt' => $built['hrt'],
                'label_disk' => 'local',
                'label_path' => 'labels/sscc/pack-guard-test.pdf',
            ]);
            $this->labelIds[] = (int) $label->getKey();

            DB::table('sscc_label_children')->insert([
                'sscc_label_id' => $label->getKey(),
                'child_epc' => $childUri,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $component = Livewire::test(PackWorkstation::class)
                ->set('scan', $childUri)
                ->call('processScan')
                ->assertCount('children', 0)
                ->assertSet('lastTone', 'warn');

            $this->assertStringContainsString('already on SSCC label', (string) $component->get('lastMessage'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pack_workstation_rejects_child_not_on_hand_at_site(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            $site = $this->createCommissionSite($tenant);
            $otherSite = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $childUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
            $child = Epc::query()->firstOrCreate(
                ['epc_uri' => $childUri],
                Epc::materializeAttributesFromUri($childUri),
            );
            $this->epcIds[] = (int) $child->getKey();
            $this->receiveAtSite($otherSite, $child);

            $component = Livewire::test(PackWorkstation::class)
                ->set('scan', $childUri)
                ->call('processScan')
                ->assertCount('children', 0)
                ->assertSet('lastTone', 'error');

            $this->assertStringContainsString('Not on hand', (string) $component->get('lastMessage'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pack_workstation_does_not_redirect_when_aggregation_fails(): void
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

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $childUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
            $child = Epc::query()->firstOrCreate(
                ['epc_uri' => $childUri],
                Epc::materializeAttributesFromUri($childUri),
            );
            $this->epcIds[] = (int) $child->getKey();
            $this->receiveAtSite($site, $child);

            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => '0399991',
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 1,
                'commission_site_id' => $site->id,
                'emit_epcis' => true,
                'status' => SsccLabelBatchStatus::Completed,
                'commissioned_at' => now(),
                'error_message' => 'EPCIS aggregation: forced aggregation failure',
            ]);
            $this->batchIds[] = (int) $batch->id;

            $built = app(SsccBuilder::class)->build('0399991', $this->uniqueSerialBase(), 0);
            $label = SsccLabel::query()->create([
                'batch_id' => $batch->id,
                'sscc_18' => $built['sscc_18'],
                'sscc_urn' => $built['sscc_urn'],
                'extension_digit' => $built['extension_digit'],
                'company_prefix' => $built['company_prefix'],
                'serial_reference' => $built['serial_reference'],
                'serial_reference_int' => $built['serial_reference_int'],
                'element_string' => $built['element_string'],
                'hrt' => $built['hrt'],
                'label_disk' => 'local',
                'label_path' => 'labels/sscc/pack-aggregation-fail.pdf',
                'commissioned_at' => now(),
            ]);
            $this->labelIds[] = (int) $label->id;

            $component = Livewire::test(PackWorkstation::class)
                ->set('scan', $childUri)
                ->call('processScan')
                ->assertCount('children', 1);

            $this->mock(GenerateSsccLabelBatch::class, function ($mock) use ($batch): void {
                $batch->load(['labels.children']);
                $mock->shouldReceive('execute')->once()->andReturn($batch);
            });

            $component->callAction('confirmPack')
                ->assertCount('children', 1)
                ->assertNoRedirect();

            $this->assertFalse($batch->fresh()->packingSucceeded());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pack_rejects_when_child_lock_is_held(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $childUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
            $child = Epc::query()->firstOrCreate(
                ['epc_uri' => $childUri],
                Epc::materializeAttributesFromUri($childUri),
            );
            $this->epcIds[] = (int) $child->getKey();
            $this->receiveAtSite($site, $child);

            $held = EpcisCacheLock::lock('pack-child:'.$tenant->getKey().':'.$child->getKey(), 30);
            $this->assertTrue($held->get());

            try {
                Livewire::test(PackWorkstation::class)
                    ->set('scan', $childUri)
                    ->call('processScan')
                    ->assertCount('children', 1)
                    ->callAction('confirmPack')
                    ->assertSet('lastTone', 'error')
                    ->assertSet('lastMessage', 'Another pack is in progress for one of these children. Try again in a moment.');
            } finally {
                $held->release();
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function break_pack_rejects_when_parent_sscc_lock_is_held(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            [$parent, $child] = $this->seedOpenHierarchy($site);

            $held = EpcisCacheLock::lock('pack-child:'.$tenant->getKey().':'.$parent->getKey(), 30);
            $this->assertTrue($held->get());

            try {
                Livewire::test(BreakPackWorkstation::class)
                    ->set('scan', (string) $parent->epc_uri)
                    ->call('processScan')
                    ->assertSet('parentEpcId', (int) $parent->getKey())
                    ->set('selectedChildIds', [(string) $child->getKey()])
                    ->callAction('confirmBreakPack')
                    ->assertSet('lastTone', 'error')
                    ->assertSet('lastMessage', 'Another pack is in progress for one of these children. Try again in a moment.');
            } finally {
                $held->release();
            }

            $this->assertTrue(
                AggregationLink::query()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $child->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
                'Break-pack must not close links when another workstation holds the parent SSCC lock.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function operations_hub_packing_and_break_pack_point_to_workstations(): void
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
            $this->assertStringContainsString('already generated', (string) $packing['description']);

            $unpacking = $directories->firstWhere('label', 'Unpacking');
            $this->assertNotNull($unpacking);
            $this->assertStringContainsString('Break a case', (string) $unpacking['description']);

            $breakPack = $directories->firstWhere('label', 'Break & pack');
            $this->assertNotNull($breakPack);
            $this->assertStringContainsString('break-pack-workstation', (string) $breakPack['url']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pack_subheading_describes_break_case_then_mixed_sscc(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $heading = (string) (new PackWorkstation)->getSubheading();
            $this->assertStringContainsString('Break a case on Unpack', $heading);
            $this->assertStringContainsString('already generated SSCC', $heading);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_generated_empty_sscc_binds_parent_and_packs_two_lots(): void
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

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $label = $this->generateEmptySscc($site);
            $childA = $this->createReceivedChild($site, 'LOT-A');
            $childB = $this->createReceivedChild($site, 'LOT-B');

            $component = Livewire::test(PackWorkstation::class)
                ->set('scan', (string) $label->sscc_18)
                ->call('processScan')
                ->assertSet('parentLabelId', (int) $label->getKey())
                ->assertSet('lastTone', 'ok')
                ->assertCount('children', 0);

            $this->assertTrue($component->instance()->isMixedLogisticsUnit() === false);

            $component
                ->set('scan', (string) $childA->epc_uri)
                ->call('processScan')
                ->assertCount('children', 1)
                ->set('scan', (string) $childB->epc_uri)
                ->call('processScan')
                ->assertCount('children', 2);

            $this->assertTrue($component->instance()->isMixedLogisticsUnit());
            $this->assertSame(2, $component->instance()->packContentSummary()['lot_count']);
            $this->assertStringContainsString('Add children to this SSCC', (string) $component->instance()->confirmPackAction()->getModalHeading());

            $component->callAction('confirmPack')
                ->assertCount('children', 0)
                ->assertSet('parentLabelId', (int) $label->getKey())
                ->assertNoRedirect()
                ->assertSet('lastTone', 'ok');

            $label->refresh();
            $this->assertSame(2, $label->children()->count());
            $this->assertTrue($label->batch?->packingSucceeded());

            $parentEpc = Epc::query()->where('sscc18', $label->sscc_18)->first();
            $this->assertNotNull($parentEpc);
            $this->assertSame(2, AggregationLink::query()
                ->open()
                ->where('parent_epc_id', $parentEpc->getKey())
                ->count());

            $addDocs = EpcisDocument::query()
                ->where('authored_kind', EpcisAuthoredKind::SsccAggregation)
                ->where('notes', 'like', '%sscc_label_id='.$label->getKey().'%')
                ->get();
            $this->assertGreaterThanOrEqual(1, $addDocs->count());
            $this->documentIds = array_merge(
                $this->documentIds,
                $addDocs->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function continue_pack_emits_incremental_add_without_duplicate_children(): void
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

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $label = $this->generateEmptySscc($site);
            $childA = $this->createReceivedChild($site, 'LOT-A');
            $childB = $this->createReceivedChild($site, 'LOT-B');
            $childC = $this->createReceivedChild($site, 'LOT-A');

            $component = Livewire::test(PackWorkstation::class)
                ->set('scan', (string) $label->sscc_urn)
                ->call('processScan')
                ->set('scan', (string) $childA->epc_uri)
                ->call('processScan')
                ->set('scan', (string) $childB->epc_uri)
                ->call('processScan')
                ->callAction('confirmPack')
                ->assertSet('lastTone', 'ok')
                ->assertSet('parentLabelId', (int) $label->getKey())
                ->assertCount('children', 0);

            $afterFirst = $this->aggregationDocumentsForLabel($label);
            $this->assertCount(1, $afterFirst);
            $firstXml = Storage::disk((string) $afterFirst[0]->payload_disk)->get((string) $afterFirst[0]->payload_path);
            $this->assertIsString($firstXml);
            $this->assertStringContainsString((string) $childA->epc_uri, $firstXml);
            $this->assertStringContainsString((string) $childB->epc_uri, $firstXml);
            $this->assertStringNotContainsString((string) $childC->epc_uri, $firstXml);

            $component
                ->set('scan', (string) $childA->epc_uri)
                ->call('processScan')
                ->assertCount('children', 0)
                ->assertSet('lastTone', 'warn');

            $this->assertStringContainsString('Already on this SSCC', (string) $component->get('lastMessage'));

            $component
                ->set('scan', (string) $childC->epc_uri)
                ->call('processScan')
                ->assertCount('children', 1)
                ->callAction('confirmPack')
                ->assertSet('lastTone', 'ok');

            $label->refresh();
            $this->assertSame(3, $label->children()->count());
            $this->assertSame(3, $label->children()->distinct()->count());

            $afterSecond = $this->aggregationDocumentsForLabel($label);
            $this->assertCount(2, $afterSecond);
            $secondXml = Storage::disk((string) $afterSecond[1]->payload_disk)->get((string) $afterSecond[1]->payload_path);
            $this->assertIsString($secondXml);
            $this->assertStringContainsString((string) $childC->epc_uri, $secondXml);
            $this->assertStringNotContainsString((string) $childA->epc_uri, $secondXml);
            $this->assertStringNotContainsString((string) $childB->epc_uri, $secondXml);

            $this->documentIds = array_merge(
                $this->documentIds,
                collect($afterSecond)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function bound_parent_still_blocks_child_on_another_live_sscc(): void
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

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $bound = $this->generateEmptySscc($site);
            $other = $this->generateEmptySscc($site);
            $child = $this->createReceivedChild($site, 'LOT-A');

            DB::table('sscc_label_children')->insert([
                'sscc_label_id' => $other->getKey(),
                'child_epc' => $child->epc_uri,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $component = Livewire::test(PackWorkstation::class)
                ->set('scan', (string) $bound->sscc_18)
                ->call('processScan')
                ->assertSet('parentLabelId', (int) $bound->getKey())
                ->set('scan', (string) $child->epc_uri)
                ->call('processScan')
                ->assertCount('children', 0)
                ->assertSet('lastTone', 'warn');

            $this->assertStringContainsString('already on SSCC label', (string) $component->get('lastMessage'));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function shipped_and_foreign_prefix_ssccs_cannot_bind(): void
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

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $ours = $this->generateEmptySscc($site);
            $emptyShip = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->shippingSessionIds[] = (int) $emptyShip->getKey();
            $emptyResult = app(ConfirmOutboundShippingScan::class)->handle($emptyShip, (string) $ours->sscc_urn);
            $this->assertFalse($emptyResult['ok']);
            $this->assertStringContainsString('no packed children', strtolower((string) $emptyResult['message']));

            $parentEpc = Epc::query()->where('sscc18', $ours->sscc_18)->first();
            $this->assertNotNull($parentEpc);
            $this->markSsccShipped($site, $parentEpc);

            $shipped = Livewire::test(PackWorkstation::class)
                ->set('scan', (string) $ours->sscc_18)
                ->call('processScan')
                ->assertSet('parentLabelId', null)
                ->assertSet('lastTone', 'error');

            $this->assertStringContainsString('already shipped', strtolower((string) $shipped->get('lastMessage')));

            $foreignBuilt = app(SsccBuilder::class)->build(
                '030116',
                $this->uniqueSerialBase(),
                0,
            );
            $foreignBatch = SsccLabelBatch::query()->create([
                'company_prefix' => '030116',
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 1,
                'status' => SsccLabelBatchStatus::Completed,
            ]);
            $this->batchIds[] = (int) $foreignBatch->getKey();

            $foreign = SsccLabel::query()->create([
                'batch_id' => $foreignBatch->getKey(),
                'sscc_18' => $foreignBuilt['sscc_18'],
                'sscc_urn' => $foreignBuilt['sscc_urn'],
                'extension_digit' => $foreignBuilt['extension_digit'],
                'company_prefix' => $foreignBuilt['company_prefix'],
                'serial_reference' => $foreignBuilt['serial_reference'],
                'serial_reference_int' => $foreignBuilt['serial_reference_int'],
                'element_string' => $foreignBuilt['element_string'],
                'hrt' => $foreignBuilt['hrt'],
                'label_disk' => 'local',
                'label_path' => 'labels/sscc/foreign-prefix.pdf',
            ]);
            $this->labelIds[] = (int) $foreign->getKey();

            $foreignScan = Livewire::test(PackWorkstation::class)
                ->set('scan', (string) $foreign->sscc_18)
                ->call('processScan')
                ->assertSet('parentLabelId', null)
                ->assertSet('lastTone', 'error');

            $this->assertStringContainsString('company prefix', strtolower((string) $foreignScan->get('lastMessage')));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function unpack_two_cases_then_pack_mixed_sscc_is_shippable_and_transferable(): void
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

            $site = $this->createCommissionSite($tenant);
            $toSite = $this->createCommissionSite($tenant);
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $site->id);
            $user = $this->actingAsWithSiteAccess($site);
            $user->syncSites([(int) $site->id, (int) $toSite->id], (int) $site->id);
            $this->prepareSerialPool($this->uniqueSerialBase());

            [$caseA, $bottleA] = $this->seedOpenHierarchy($site);
            [$caseB, $bottleB] = $this->seedOpenHierarchy($site);
            $this->assignLot($bottleA, 'LOT-A');
            $this->assignLot($bottleB, 'LOT-B');

            $unpackedA = app(UnpackReceivingHierarchy::class)->handleParent($caseA, [(int) $bottleA->getKey()], $site);
            $unpackedB = app(UnpackReceivingHierarchy::class)->handleParent($caseB, [(int) $bottleB->getKey()], $site);
            $this->documentIds[] = (int) $unpackedA['document']->getKey();
            $this->documentIds[] = (int) $unpackedB['document']->getKey();

            $this->assertFalse(
                AggregationLink::query()
                    ->where('child_epc_id', $bottleA->getKey())
                    ->whereNull('valid_to')
                    ->exists(),
            );

            $label = $this->generateEmptySscc($site);

            Livewire::test(PackWorkstation::class)
                ->set('scan', (string) $label->sscc_18)
                ->call('processScan')
                ->set('scan', (string) $bottleA->epc_uri)
                ->call('processScan')
                ->set('scan', (string) $bottleB->epc_uri)
                ->call('processScan')
                ->assertCount('children', 2)
                ->callAction('confirmPack')
                ->assertSet('lastTone', 'ok');

            $label->refresh();
            $this->assertSame(2, $label->children()->count());
            $this->assertTrue($label->batch?->packingSucceeded());

            $shipSession = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->shippingSessionIds[] = (int) $shipSession->getKey();
            $ship = app(ConfirmOutboundShippingScan::class)->handle($shipSession, (string) $label->sscc_urn);
            $this->assertTrue($ship['ok'], $ship['message']);
            $this->assertSame('confirmed', $ship['effect']);

            OutboundShippingScanLine::query()->where('outbound_shipping_session_id', $shipSession->getKey())->delete();
            $shipSession->delete();
            array_pop($this->shippingSessionIds);

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $site->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionIds[] = (int) $transfer->getKey();
            $moved = app(ConfirmTransferringScan::class)->handle($transfer, (string) $label->sscc_18);
            $this->assertTrue($moved['ok'], $moved['message']);
            $this->assertSame('confirmed', $moved['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function unpack_after_pack_blocks_ship_and_transfer_of_empty_tenant_sscc(): void
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

            $site = $this->createCommissionSite($tenant);
            $toSite = $this->createCommissionSite($tenant);
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $site->id);
            $user = $this->actingAsWithSiteAccess($site);
            $user->syncSites([(int) $site->id, (int) $toSite->id], (int) $site->id);
            $this->prepareSerialPool($this->uniqueSerialBase());

            [$case, $bottle] = $this->seedOpenHierarchy($site);
            $this->assignLot($bottle, 'LOT-A');
            $unpacked = app(UnpackReceivingHierarchy::class)->handleParent($case, [(int) $bottle->getKey()], $site);
            $this->documentIds[] = (int) $unpacked['document']->getKey();

            $label = $this->generateEmptySscc($site);
            Livewire::test(PackWorkstation::class)
                ->set('scan', (string) $label->sscc_18)
                ->call('processScan')
                ->set('scan', (string) $bottle->epc_uri)
                ->call('processScan')
                ->callAction('confirmPack')
                ->assertSet('lastTone', 'ok');

            $parent = Epc::query()->where('sscc18', $label->sscc_18)->first();
            $this->assertNotNull($parent);
            $this->assertTrue(
                AggregationLink::query()
                    ->open()
                    ->where('parent_epc_id', $parent->getKey())
                    ->exists(),
            );

            $broke = app(UnpackReceivingHierarchy::class)->handleParent($parent, [(int) $bottle->getKey()], $site);
            $this->documentIds[] = (int) $broke['document']->getKey();

            $this->assertFalse(
                AggregationLink::query()
                    ->open()
                    ->where('parent_epc_id', $parent->getKey())
                    ->exists(),
            );
            $this->assertGreaterThan(0, $label->fresh()->children()->count());

            $shipSession = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->shippingSessionIds[] = (int) $shipSession->getKey();
            $ship = app(ConfirmOutboundShippingScan::class)->handle($shipSession, (string) $label->sscc_urn);
            $this->assertFalse($ship['ok']);
            $this->assertStringContainsString('no packed children', strtolower((string) $ship['message']));

            $transfer = app(OpenTransferringSession::class)->handle(
                fromSiteId: (int) $site->getKey(),
                toSiteId: (int) $toSite->getKey(),
            );
            $this->transferSessionIds[] = (int) $transfer->getKey();
            $moved = app(ConfirmTransferringScan::class)->handle($transfer, (string) $label->sscc_18);
            $this->assertFalse($moved['ok']);
            $this->assertStringContainsString('no packed children', strtolower((string) $moved['message']));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function send_blocks_tenant_sscc_confirmed_then_unpacked(): void
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

            $site = $this->createCommissionSite($tenant);
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $site->id);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            [$case, $bottle] = $this->seedOpenHierarchy($site);
            $this->assignLot($bottle, 'LOT-A');
            $unpacked = app(UnpackReceivingHierarchy::class)->handleParent($case, [(int) $bottle->getKey()], $site);
            $this->documentIds[] = (int) $unpacked['document']->getKey();

            $label = $this->generateEmptySscc($site);
            Livewire::test(PackWorkstation::class)
                ->set('scan', (string) $label->sscc_18)
                ->call('processScan')
                ->set('scan', (string) $bottle->epc_uri)
                ->call('processScan')
                ->callAction('confirmPack')
                ->assertSet('lastTone', 'ok');

            $parent = Epc::query()->where('sscc18', $label->sscc_18)->firstOrFail();

            $shipSession = app(OpenOutboundShippingSession::class)->handle((int) $site->getKey());
            $this->shippingSessionIds[] = (int) $shipSession->getKey();
            $ship = app(ConfirmOutboundShippingScan::class)->handle($shipSession, (string) $label->sscc_urn);
            $this->assertTrue($ship['ok'], (string) ($ship['message'] ?? ''));

            $broke = app(UnpackReceivingHierarchy::class)->handleParent($parent, [(int) $bottle->getKey()], $site);
            $this->documentIds[] = (int) $broke['document']->getKey();
            $this->assertGreaterThan(0, $label->fresh()->children()->count());

            $blockers = app(ValidateOutboundShippingSend::class)->handle($shipSession->fresh() ?? $shipSession);
            $this->assertTrue(
                collect($blockers)->contains(
                    fn (string $blocker): bool => str_contains(strtolower($blocker), 'no packed children'),
                ),
                'Send-time empty-plate gate must block a confirmed SSCC unpacked before send. Blockers: '.implode(' | ', $blockers),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function unpack_then_rebind_same_sscc_allows_readd_and_clears_hud(): void
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

            $site = $this->createCommissionSite($tenant);
            TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId((int) $site->id);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            [$case, $bottle] = $this->seedOpenHierarchy($site);
            $this->assignLot($bottle, 'LOT-A');
            $unpacked = app(UnpackReceivingHierarchy::class)->handleParent($case, [(int) $bottle->getKey()], $site);
            $this->documentIds[] = (int) $unpacked['document']->getKey();

            $label = $this->generateEmptySscc($site);
            $component = Livewire::test(PackWorkstation::class)
                ->set('scan', (string) $label->sscc_18)
                ->call('processScan')
                ->set('scan', (string) $bottle->epc_uri)
                ->call('processScan')
                ->callAction('confirmPack')
                ->assertSet('lastTone', 'ok')
                ->assertSet('parentLabelId', (int) $label->getKey());

            $parent = Epc::query()->where('sscc18', $label->sscc_18)->firstOrFail();
            $broke = app(UnpackReceivingHierarchy::class)->handleParent($parent, [(int) $bottle->getKey()], $site);
            $this->documentIds[] = (int) $broke['document']->getKey();
            $this->assertGreaterThan(0, $label->fresh()->children()->count());

            $this->assertSame(0, $component->instance()->boundParentChildCount());
            $this->assertSame(
                ['lot_count' => 0, 'gtin_count' => 0, 'is_mixed' => false],
                $component->instance()->packContentSummary(),
            );

            $component
                ->set('scan', (string) $bottle->epc_uri)
                ->call('processScan')
                ->assertCount('children', 1)
                ->assertSet('lastTone', 'ok')
                ->callAction('confirmPack')
                ->assertSet('lastTone', 'ok');

            $this->assertTrue(
                AggregationLink::query()
                    ->open()
                    ->where('parent_epc_id', $parent->getKey())
                    ->where('child_epc_id', $bottle->getKey())
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function inbound_manufacturer_sscc_without_label_is_not_blocked_by_empty_plate_gate(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $parentUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $parent = Epc::query()->create(Epc::materializeAttributesFromUri($parentUri));
            $this->epcIds[] = (int) $parent->getKey();

            $this->assertFalse(
                SsccLabel::query()
                    ->where('sscc_urn', $parent->epc_uri)
                    ->orWhere('sscc_18', $parent->sscc18)
                    ->exists(),
            );

            app(AssertOutermostSsccHasChildren::class)->handle($parent);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function actingAsWithSiteAccess(Site $site): User
    {
        $user = User::factory()->create();
        $user->syncSites([(int) $site->id], (int) $site->id);
        $this->actingAs($user);

        return $user;
    }

    private function generateEmptySscc(Site $site): SsccLabel
    {
        $batch = app(GenerateSsccLabelBatch::class)->execute([
            'allocation_mode' => SsccAllocationMode::Sequential->value,
            'label_count' => 1,
            'copies_per_label' => 1,
            'site_id' => $site->id,
            'emit_epcis' => false,
            'send_to_printer' => false,
        ]);

        $this->batchIds[] = (int) $batch->getKey();
        $label = $batch->labels()->first();
        $this->assertNotNull($label);
        $this->labelIds[] = (int) $label->getKey();
        $this->trackCommissioningArtifacts($batch);

        return $label;
    }

    private function createReceivedChild(Site $site, string $lot): Epc
    {
        $childUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);
        $child = Epc::query()->firstOrCreate(
            ['epc_uri' => $childUri],
            Epc::materializeAttributesFromUri($childUri),
        );
        $this->epcIds[] = (int) $child->getKey();
        $this->assignLot($child, $lot);
        $this->receiveAtSite($site, $child);

        return $child->fresh() ?? $child;
    }

    private function assignLot(Epc $epc, string $lot): void
    {
        EpcIlmd::query()->updateOrCreate(
            ['epc_id' => $epc->getKey()],
            [
                'gtin14' => $epc->gtin14,
                'lot_number' => $lot,
                'expiry_date' => '2027-12-31',
            ],
        );
    }

    private function markSsccShipped(Site $site, Epc $parent): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Shipping,
            'status' => 'parsed',
            'original_filename' => 'pack-shipped-bind.xml',
            'notes' => 'Generated outbound shipping EPCIS for ship order session #0.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now()->addMinute(),
            'record_time' => now()->addMinute(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => '0614141000005',
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insert([
            'event_id' => $event->getKey(),
            'epc_id' => $parent->getKey(),
            'role' => 'epcList',
        ]);
    }

    /**
     * @return list<EpcisDocument>
     */
    private function aggregationDocumentsForLabel(SsccLabel $label): array
    {
        return EpcisDocument::query()
            ->where('authored_kind', EpcisAuthoredKind::SsccAggregation)
            ->where('notes', 'like', '%sscc_label_id='.$label->getKey().'%')
            ->orderBy('id')
            ->get()
            ->all();
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
            'name' => 'Pack Workstation Commission Site',
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

    /**
     * @return array{0: Epc, 1: Epc}
     */
    private function seedOpenHierarchy(Site $site): array
    {
        $parentUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        $childUri = 'urn:epc:id:sgtin:030116.5200116.'.(string) random_int(90000000000000, 99999999999999);

        $parent = Epc::query()->create(Epc::materializeAttributesFromUri($parentUri));
        $child = Epc::query()->create(Epc::materializeAttributesFromUri($childUri));
        $this->epcIds[] = (int) $parent->getKey();
        $this->epcIds[] = (int) $child->getKey();

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'inbound',
            'status' => 'validated',
            'original_filename' => 'break-pack-lock-source.xml',
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

        $link = AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $child->getKey(),
            'established_by_event_id' => $event->getKey(),
            'link_type' => 'aggregation',
            'valid_from' => now()->subMinute(),
            'valid_to' => null,
        ]);
        $this->linkIds[] = (int) $link->getKey();

        $this->receiveAtSite($site, $parent, $child);

        return [$parent, $child];
    }

    /**
     * Packing requires tenant custody, which comes from a receiving event at one of
     * our GLNs — the same ObjectEvent GenerateReceivingEpcisEvents authors on receipt.
     */
    private function receiveAtSite(Site $site, Epc ...$epcs): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'pack-workstation-receipt.xml',
        ]);
        $this->documentIds[] = (int) $document->getKey();

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
        if ($this->shippingSessionIds !== []) {
            OutboundShippingScanLine::query()
                ->whereIn('outbound_shipping_session_id', $this->shippingSessionIds)
                ->delete();
            OutboundShippingSession::query()->whereIn('id', $this->shippingSessionIds)->delete();
        }

        if ($this->transferSessionIds !== []) {
            TransferringScanLine::query()
                ->whereIn('transferring_session_id', $this->transferSessionIds)
                ->delete();
            TransferringSession::query()->whereIn('id', $this->transferSessionIds)->delete();
        }

        if ($this->linkIds !== []) {
            AggregationLink::query()->whereIn('id', $this->linkIds)->delete();
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
        }

        if ($this->labelIds !== []) {
            DB::table('sscc_label_children')->whereIn('sscc_label_id', $this->labelIds)->delete();
            SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
        }

        if ($this->batchIds !== []) {
            SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
        }

        if ($this->documentIds !== []) {
            DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }

        if ($this->epcIds !== []) {
            EpcIlmd::query()->whereIn('epc_id', $this->epcIds)->delete();
            DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            AggregationLink::query()
                ->where(function ($query): void {
                    $query->whereIn('parent_epc_id', $this->epcIds)
                        ->orWhereIn('child_epc_id', $this->epcIds);
                })
                ->delete();
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
