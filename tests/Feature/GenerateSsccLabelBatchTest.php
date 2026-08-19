<?php

namespace Tests\Feature;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Labeling\DispatchSsccBatchPrint;
use App\Actions\Labeling\GenerateSsccLabelBatch;
use App\Enums\LabelPrinterProtocol;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\SsccLabelPrintStatus;
use App\Enums\SsccPrintJobStatus;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\SsccLabels\Pages\ViewSsccLabelBatch;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Jobs\PrintSsccLabelJob;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\LabelPrinter;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\SsccLabelChild;
use App\Models\SsccPrintJob;
use App\Models\SsccSerialPool;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Labeling\SsccLabelPdfGenerator;
use App\Support\Gs1\Sgln;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateSsccLabelBatchTest extends TestCase
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
    private array $printerIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    private ?int $priorPoolLastSerial = null;

    private ?int $priorDefaultShipFromSiteId = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function generates_sequential_batch_with_pdf_paths(): void
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

            $serialBase = $this->uniqueSerialBase();
            $pool = $this->prepareSerialPool($serialBase);

            $batch = app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 2,
                'copies_per_label' => 1,
                'ship_to_name' => 'Test DC',
                'site_id' => $site->id,
            ]);

            $this->batchIds[] = (int) $batch->id;
            $this->labelIds = array_merge(
                $this->labelIds,
                $batch->labels->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );

            $this->assertSame(SsccLabelBatchStatus::Completed, $batch->status);
            $this->assertSame(2, $batch->labels()->count());
            $this->assertSame(
                [$serialBase + 1, $serialBase + 2],
                $batch->labels()->orderBy('serial_reference_int')->pluck('serial_reference_int')->all(),
            );

            $pool->refresh();
            $this->assertSame($serialBase + 2, $pool->last_serial_reference_int);

            $batch->labels->each(function (SsccLabel $label): void {
                $this->assertNotNull($label->label_path);
                $this->assertNotNull($label->label_disk);
                Storage::disk($label->label_disk)->assertExists($label->label_path);
            });

            $this->trackCommissioningArtifacts($batch);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_commissions_sync_and_resolve_epc_from_scan_finds_ai00(): void
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

            $batch = app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'site_id' => $site->id,
            ]);

            $this->batchIds[] = (int) $batch->id;
            $label = $batch->labels->first();
            $this->assertNotNull($label);
            $this->labelIds[] = (int) $label->id;
            $this->trackCommissioningArtifacts($batch);

            $batch->refresh();
            $label->refresh();

            $this->assertNotNull($batch->commissioned_at);
            $this->assertNotNull($label->commissioned_at);
            $this->assertSame((int) $site->id, (int) $batch->commission_site_id);

            $epc = Epc::query()->where('sscc18', $label->sscc_18)->first();
            $this->assertNotNull($epc, 'Epc row should exist for commissioned sscc18');
            $this->epcIds[] = (int) $epc->id;

            $scan = '00'.$label->sscc_18;
            $resolved = app(ResolveEpcFromScan::class)->handle($scan);

            $this->assertNotNull($resolved['epc'], 'ResolveEpcFromScan should find 00+sscc18');
            $this->assertTrue($resolved['epc']->is($epc));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pdf_write_failure_still_commissions_and_does_not_dispatch_print(): void
    {
        Storage::fake('local');
        Queue::fake([PrintSsccLabelJob::class]);

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $printer = LabelPrinter::query()->create([
                'name' => 'Commission PDF Fail Printer',
                'ip_address' => '10.0.0.91',
                'port' => 9100,
                'protocol' => LabelPrinterProtocol::ZplRaw,
                'enabled' => true,
            ]);
            $this->printerIds[] = (int) $printer->id;

            $this->prepareSerialPool($this->uniqueSerialBase());

            $pdf = $this->createMock(SsccLabelPdfGenerator::class);
            $pdf->method('generate')->willThrowException(new \RuntimeException('forced pdf failure'));
            $this->app->instance(SsccLabelPdfGenerator::class, $pdf);

            $printDispatcher = $this->createMock(DispatchSsccBatchPrint::class);
            $printDispatcher->expects($this->never())->method('execute');
            $this->app->instance(DispatchSsccBatchPrint::class, $printDispatcher);

            $batch = app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'site_id' => $site->id,
                'send_to_printer' => true,
                'label_printer_id' => $printer->id,
            ]);

            $this->batchIds[] = (int) $batch->id;
            $label = $batch->labels->first();
            $this->assertNotNull($label);
            $this->labelIds[] = (int) $label->id;
            $this->trackCommissioningArtifacts($batch);

            $batch->refresh();
            $label->refresh();

            $this->assertNotNull($batch->commissioned_at);
            $this->assertNotNull($label->commissioned_at);
            $this->assertNotNull($batch->error_message);
            $this->assertStringStartsWith('PDF:', (string) $batch->error_message);

            Queue::assertNothingPushed();
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function view_batch_surfaces_failed_print_error_and_retries_label(): void
    {
        Queue::fake([PrintSsccLabelJob::class]);

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $printer = LabelPrinter::query()->create([
                'name' => 'Retry Printer',
                'ip_address' => '10.0.0.92',
                'port' => 9100,
                'protocol' => LabelPrinterProtocol::ZplRaw,
                'enabled' => true,
            ]);
            $this->printerIds[] = (int) $printer->id;

            $batch = SsccLabelBatch::query()->create([
                'company_prefix' => '0399991',
                'extension_digit' => '0',
                'allocation_mode' => SsccAllocationMode::Sequential,
                'label_count' => 1,
                'copies_per_label' => 1,
                'label_printer_id' => $printer->id,
                'send_to_printer' => true,
                'status' => SsccLabelBatchStatus::Completed,
                'commission_site_id' => $site->id,
                'commissioned_at' => now(),
            ]);
            $this->batchIds[] = (int) $batch->id;

            $serialInt = random_int(9100000, 9199999);
            $serialRef = str_pad((string) $serialInt, 9, '0', STR_PAD_LEFT);
            $ssccBody = '0'.'0399991'.$serialRef;
            $sscc18 = $ssccBody.$this->gs1CheckDigit($ssccBody);

            $label = SsccLabel::query()->create([
                'batch_id' => $batch->id,
                'label_printer_id' => $printer->id,
                'sscc_18' => $sscc18,
                'sscc_urn' => 'urn:epc:id:sscc:0399991.0'.$serialRef,
                'extension_digit' => '0',
                'company_prefix' => '0399991',
                'serial_reference' => $serialRef,
                'serial_reference_int' => $serialInt,
                'element_string' => '00'.$sscc18,
                'hrt' => '00'.$sscc18,
                'label_disk' => 'local',
                'label_path' => 'sscc/retry-test.pdf',
                'print_status' => SsccLabelPrintStatus::Failed,
                'commissioned_at' => now(),
            ]);
            $this->labelIds[] = (int) $label->id;

            SsccPrintJob::query()->create([
                'sscc_label_batch_id' => $batch->id,
                'sscc_label_id' => $label->id,
                'label_printer_id' => $printer->id,
                'copies' => 1,
                'status' => SsccPrintJobStatus::Failed,
                'attempts' => 3,
                'last_error' => 'Printer connection timed out.',
                'queued_at' => now()->subMinute(),
            ]);

            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ViewSsccLabelBatch::class, ['record' => $batch->id])
                ->assertSee('Failed')
                ->assertSee('Printer connection timed out.')
                ->assertSee('Retry print')
                ->call('retryPrintLabel', $label->id)
                ->assertSee('Queued');

            $label->refresh();
            $this->assertSame(SsccLabelPrintStatus::Queued, $label->print_status);
            $this->assertSame(
                1,
                SsccPrintJob::query()
                    ->where('sscc_label_id', $label->id)
                    ->where('status', SsccPrintJobStatus::Queued)
                    ->count(),
            );
            Queue::assertPushed(PrintSsccLabelJob::class);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function commissioning_uses_explicit_site_gln_in_epcis_xml(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            // Fixed 13-digit site GLN under tenant GCP 0399991 (7) + location 00001.
            $siteGln = '039999100001'.$this->gs1CheckDigit('039999100001');
            $site = $this->createCommissionSite($tenant, $siteGln);
            $this->actingAsWithSiteAccess($site);

            $this->prepareSerialPool($this->uniqueSerialBase());

            $batch = app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'site_id' => $site->id,
            ]);

            $this->batchIds[] = (int) $batch->id;
            $label = $batch->labels->first();
            $this->assertNotNull($label);
            $this->labelIds[] = (int) $label->id;
            $this->trackCommissioningArtifacts($batch);

            $batch->refresh();
            $this->assertNotNull($batch->commissioning_epcis_file_path);

            $xml = Storage::disk((string) config('tracepharma.epcis.payload_disk', 'local'))
                ->get($batch->commissioning_epcis_file_path);

            $this->assertNotNull($xml);

            $expectedSgln = Sgln::toUrn($siteGln, 7);
            $this->assertNotNull($expectedSgln);
            $this->assertStringContainsString($expectedSgln, $xml);

            $eventGln = DB::table('epcis_events')
                ->whereIn('document_id', $this->documentIds)
                ->value('biz_location_gln');

            if ($eventGln !== null) {
                $this->assertSame($siteGln, (string) $eventGln);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function filament_resource_can_access_when_packing_or_sscc_labeling_supported(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsPacking());
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsSsccLabeling());
            $this->assertTrue(SsccLabelResource::canAccess());

            $this->setProfile($tenant, TenantProfile::BuyingGroup);
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsPacking());
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsSsccLabeling());
            $this->assertFalse(SsccLabelResource::canAccess());

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsSsccLabeling());
            $this->assertTrue(SsccLabelResource::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_rejects_when_tenant_identity_matches_trading_partner(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            // Temporarily set identity to Xttrium partner GLN already present in demo2.
            $tenant->forceFill([
                'gln' => '0301160000009',
                'company_prefix' => '030116',
            ])->save();

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('trading partner');

            app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'site_id' => $site->id,
            ]);
        } finally {
            $tenant->forceFill([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ])->save();
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_rejects_flat_child_epcs_with_multiple_labels(): void
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

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Cannot attach the same child EPCs to multiple parent labels');

            app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 2,
                'copies_per_label' => 1,
                'site_id' => $site->id,
                'child_epcs' => "urn:epc:id:sgtin:030116.5200116.1\nurn:epc:id:sgtin:030116.5200116.2",
            ]);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_rejects_caller_company_prefix_that_differs_from_tenant(): void
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

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('must match the tenant organization company prefix');

            app(GenerateSsccLabelBatch::class)->execute([
                'company_prefix' => '030116',
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'site_id' => $site->id,
            ]);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_rejects_child_epc_urns_that_are_not_on_record(): void
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

            $unknownUrn = 'urn:epc:id:sgtin:030116.5200116.'.random_int(700000000, 799999999);
            $this->assertFalse(Epc::query()->where('epc_uri', $unknownUrn)->exists());

            $batchIdBefore = (int) SsccLabelBatch::query()->max('id');

            try {
                app(GenerateSsccLabelBatch::class)->execute([
                    'allocation_mode' => SsccAllocationMode::Sequential->value,
                    'label_count' => 1,
                    'copies_per_label' => 1,
                    'site_id' => $site->id,
                    'child_epcs' => $unknownUrn,
                ]);

                $this->fail('Child EPC URNs with no EPC row should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Unknown child EPC', $exception->getMessage());
            }

            $this->trackFailedAuditBatches($batchIdBefore);

            $this->assertSame(
                0,
                DB::table('sscc_label_children')->where('child_epc', $unknownUrn)->count(),
                'A rejected child EPC must not be attached to any label.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function generate_rejects_commission_site_the_user_cannot_access(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
            ]);

            $allowedSite = $this->createCommissionSite($tenant);
            $forbiddenSite = $this->createCommissionSite($tenant);
            $user = User::factory()->create();
            $user->syncSites([(int) $allowedSite->id], (int) $allowedSite->id);
            $this->actingAs($user);

            $this->prepareSerialPool($this->uniqueSerialBase());

            $this->expectException(AuthorizationException::class);

            app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'site_id' => $forbiddenSite->id,
            ]);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function aggregation_failure_detaches_children_and_records_error(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
                'l3_enabled' => false,
                'l3_endpoint_url' => null,
            ]);
            TenantSettings::forTenant($tenant)->setL3Enabled(false);
            $tenant->save();

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $childUri = 'urn:epc:id:sgtin:030116.5200116.'.random_int(90000000000000, 99999999999999);
            $child = Epc::query()->firstOrCreate(
                ['epc_uri' => $childUri],
                Epc::materializeAttributesFromUri($childUri),
            );
            $this->epcIds[] = (int) $child->getKey();
            $this->receiveChildAtSite($site, $child);

            $batch = app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'site_id' => $site->id,
                'child_epcs' => $childUri,
                'emit_epcis' => false,
            ]);

            $this->batchIds[] = (int) $batch->id;
            $this->labelIds = array_merge(
                $this->labelIds,
                $batch->labels->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
            $this->trackCommissioningArtifacts($batch);

            $this->assertSame(1, DB::table('sscc_label_children')->where('child_epc', $childUri)->count());

            $method = new \ReflectionMethod(GenerateSsccLabelBatch::class, 'failAfterAggregationError');
            $method->setAccessible(true);
            $method->invoke(app(GenerateSsccLabelBatch::class), $batch, new \RuntimeException('forced aggregation failure'));

            $batch->refresh();
            $this->assertTrue($batch->hasAggregationError());
            $this->assertFalse($batch->packingSucceeded());
            $this->assertSame(0, DB::table('sscc_label_children')->where('child_epc', $childUri)->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function sscc_epcis_documents_carry_ship_from_site_id(): void
    {
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0399991000008',
                'company_prefix' => '0399991',
                'l3_enabled' => false,
                'l3_endpoint_url' => null,
            ]);
            TenantSettings::forTenant($tenant)->setL3Enabled(false);
            $tenant->save();

            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);
            $this->prepareSerialPool($this->uniqueSerialBase());

            $childUri = 'urn:epc:id:sgtin:030116.5200116.'.random_int(90000000000000, 99999999999999);
            $child = Epc::query()->firstOrCreate(
                ['epc_uri' => $childUri],
                Epc::materializeAttributesFromUri($childUri),
            );
            $this->epcIds[] = (int) $child->getKey();
            $this->receiveChildAtSite($site, $child);

            $batch = app(GenerateSsccLabelBatch::class)->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'site_id' => $site->id,
                'child_epcs' => $childUri,
                'emit_epcis' => true,
                'epcis_sync' => true,
            ]);

            $this->batchIds[] = (int) $batch->id;
            $this->labelIds = array_merge(
                $this->labelIds,
                $batch->labels->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            );
            $this->trackCommissioningArtifacts($batch);

            $documents = EpcisDocument::query()
                ->where('notes', 'like', '%sscc_label_batch_id='.$batch->id.'%')
                ->get();

            $this->assertNotEmpty($documents);
            foreach ($documents as $document) {
                $this->assertSame((int) $site->id, (int) $document->ship_from_site_id, (string) $document->notes);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function view_batch_rejects_child_epc_edit_for_urn_that_is_not_on_record(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $label = $this->createCommissionedLabel($site);
            $unknownUrn = 'urn:epc:id:sgtin:030116.5200116.'.random_int(600000000, 699999999);

            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ViewSsccLabelBatch::class, ['record' => $label->batch_id])
                ->call('openChildrenEditor', $label->id)
                ->set('childEpcsText', $unknownUrn)
                ->call('saveChildren');

            $this->assertSame(0, $label->children()->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function view_batch_rejects_child_epc_edit_for_epc_out_of_tenant_custody(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $label = $this->createCommissionedLabel($site);
            $foreignEpc = $this->createUnreceivedEpc();

            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ViewSsccLabelBatch::class, ['record' => $label->batch_id])
                ->call('openChildrenEditor', $label->id)
                ->set('childEpcsText', (string) $foreignEpc->epc_uri)
                ->call('saveChildren');

            $this->assertSame(0, $label->children()->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function view_batch_does_not_emit_aggregation_for_children_out_of_tenant_custody(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::DrugWholesaler);
            $site = $this->createCommissionSite($tenant);
            $this->actingAsWithSiteAccess($site);

            $label = $this->createCommissionedLabel($site);
            $foreignEpc = $this->createUnreceivedEpc();

            SsccLabelChild::query()->create([
                'sscc_label_id' => $label->id,
                'child_epc' => (string) $foreignEpc->epc_uri,
            ]);

            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ViewSsccLabelBatch::class, ['record' => $label->batch_id])
                ->call('emitEpcis');

            $batch = SsccLabelBatch::query()->findOrFail($label->batch_id);
            $this->assertNull($batch->epcis_emitted_at);
            $this->assertNull($label->fresh()->epcis_emitted_at);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * An EPC we have never received and never commissioned: it exists in the event store
     * only as an identity, so it is out of tenant custody.
     */
    private function createUnreceivedEpc(): Epc
    {
        $urn = 'urn:epc:id:sgtin:030116.5200116.'.random_int(500000000, 599999999);

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($urn));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createCommissionedLabel(Site $site): SsccLabel
    {
        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '0399991',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 1,
            'send_to_printer' => false,
            'status' => SsccLabelBatchStatus::Completed,
            'commission_site_id' => $site->id,
            'commissioned_at' => now(),
        ]);
        $this->batchIds[] = (int) $batch->id;

        $serialInt = random_int(9200000, 9299999);
        $serialRef = str_pad((string) $serialInt, 9, '0', STR_PAD_LEFT);
        $ssccBody = '00399991'.$serialRef;
        $sscc18 = $ssccBody.$this->gs1CheckDigit($ssccBody);

        $label = SsccLabel::query()->create([
            'batch_id' => $batch->id,
            'sscc_18' => $sscc18,
            'sscc_urn' => 'urn:epc:id:sscc:0399991.0'.$serialRef,
            'extension_digit' => '0',
            'company_prefix' => '0399991',
            'serial_reference' => $serialRef,
            'serial_reference_int' => $serialInt,
            'element_string' => '00'.$sscc18,
            'hrt' => '00'.$sscc18,
            'label_disk' => 'local',
            'label_path' => 'sscc/custody-gate-test.pdf',
            'print_status' => SsccLabelPrintStatus::Skipped,
            'commissioned_at' => now(),
        ]);
        $this->labelIds[] = (int) $label->id;

        return $label;
    }

    /**
     * A rejected generation still records a Failed batch for audit; adopt it so cleanup
     * does not leave rows behind.
     */
    private function trackFailedAuditBatches(int $afterBatchId): void
    {
        $failed = SsccLabelBatch::query()
            ->where('status', SsccLabelBatchStatus::Failed)
            ->where('id', '>', $afterBatchId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->batchIds = array_values(array_unique(array_merge($this->batchIds, $failed)));
    }

    private function actingAsWithSiteAccess(Site $site): User
    {
        $user = User::factory()->create();
        $user->syncSites([(int) $site->id], (int) $site->id);
        $this->actingAs($user);

        return $user;
    }

    private function receiveChildAtSite(Site $site, Epc $child): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'sscc-batch-child-receipt.xml',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = \App\Models\Epcis\EpcisEvent::query()->create([
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

        DB::table('event_epcs')->insertOrIgnore([
            'event_id' => $event->getKey(),
            'epc_id' => $child->getKey(),
            'role' => 'epcList',
        ]);
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
        // now asserts this), so derive the default from whatever prefix the test configured.
        $sitePrefix = $settings->companyPrefix() ?? '030116';

        $site = Site::query()->create([
            'name' => 'Commission Site '.Str::random(6),
            'gln' => $gln ?? $this->uniqueOrgGln($sitePrefix),
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

    private function uniqueOrgGln(string $companyPrefix): string
    {
        $prefixLen = strlen($companyPrefix);
        $locationLen = 12 - $prefixLen;

        if ($locationLen < 1 || $locationLen > 6) {
            throw new InvalidArgumentException('Company prefix must leave 1–6 digits for the GLN location reference.');
        }

        $max = (10 ** $locationLen) - 1;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $body12 = $companyPrefix.str_pad((string) random_int(0, $max), $locationLen, '0', STR_PAD_LEFT);
            $gln = $body12.$this->gs1CheckDigit($body12);

            if (! Site::query()->where('gln', $gln)->exists()) {
                return $gln;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique commission site GLN for the test.');
    }

    private function gs1CheckDigit(string $bodyWithoutCheck): string
    {
        $sum = 0;
        $digits = str_split(strrev($bodyWithoutCheck));

        foreach ($digits as $index => $digit) {
            $sum += ((int) $digit) * ($index % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - ($sum % 10)) % 10);
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
        $tenant->setAttribute('profile', $profile);
        $tenant->save();
        tenancy()->initialize($tenant->fresh());
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->documentIds !== []) {
                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
                }

                if (Schema::hasTable('epcis_events')) {
                    $eventIds = DB::table('epcis_events')
                        ->whereIn('document_id', $this->documentIds)
                        ->pluck('id')
                        ->all();

                    if ($eventIds !== [] && Schema::hasTable('event_locations')) {
                        DB::table('event_locations')->whereIn('event_id', $eventIds)->delete();
                    }

                    if ($eventIds !== [] && Schema::hasTable('event_epcs')) {
                        DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                    }

                    DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
                }

                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }

            if ($this->epcIds !== []) {
                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
                }

                Epc::query()->whereIn('id', $this->epcIds)->delete();
            }

            if ($this->labelIds !== []) {
                SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
            }

            if ($this->batchIds !== []) {
                SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
            }

            if ($this->priorPoolLastSerial !== null && $this->poolIds !== []) {
                SsccSerialPool::query()
                    ->whereIn('id', $this->poolIds)
                    ->update(['last_serial_reference_int' => $this->priorPoolLastSerial]);
            }

            if ($this->printerIds !== []) {
                LabelPrinter::query()->whereIn('id', $this->printerIds)->delete();
            }

            if ($this->priorDefaultShipFromSiteId !== null || $this->siteIds !== []) {
                TenantSettings::forTenant($tenant)->setDefaultShipFromSiteId($this->priorDefaultShipFromSiteId);
                $tenant->save();
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }

            tenancy()->end();
        }

        if ($this->priorProfile !== null) {
            $tenant->setAttribute('profile', $this->priorProfile);
            $tenant->save();
        }

        $this->labelIds = [];
        $this->batchIds = [];
        $this->documentIds = [];
        $this->poolIds = [];
        $this->printerIds = [];
        $this->siteIds = [];
        $this->epcIds = [];
        $this->priorPoolLastSerial = null;
        $this->priorDefaultShipFromSiteId = null;
    }
}
