<?php

namespace Tests\Feature\Labeling;

use App\Actions\Labeling\ReconcileSsccBatchL3L4;
use App\Enums\ExceptionStatus;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Models\Tenant;
use App\Services\Labeling\SsccBuilder;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReconcileSsccBatchL3L4Test extends TestCase
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
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $exceptionCaseIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function equal_counts_do_not_open_a_case(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureExceptionTypes();
            $site = $this->createSite();
            [$batch, $epc] = $this->createCommissionedBatchWithLabel($site);
            $this->authorCommissioningEvent($epc);

            $before = ExceptionCase::query()
                ->where('exception_type_id', $this->typeId())
                ->count();

            $result = app(ReconcileSsccBatchL3L4::class)->handle($batch);

            $this->assertTrue($result['matched']);
            $this->assertSame(1, $result['expected']);
            $this->assertSame(1, $result['actual']);
            $this->assertNull($result['exception_case_id']);
            $this->assertFalse($result['opened']);
            $this->assertSame(
                $before,
                ExceptionCase::query()->where('exception_type_id', $this->typeId())->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function l4_short_opens_reconciliation_case(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureExceptionTypes();
            $site = $this->createSite();
            [$batch] = $this->createCommissionedBatchWithLabel($site);
            // No commissioning ObjectEvent → L4 short.

            $result = app(ReconcileSsccBatchL3L4::class)->handle($batch);

            $this->assertFalse($result['matched']);
            $this->assertSame(1, $result['expected']);
            $this->assertSame(0, $result['actual']);
            $this->assertTrue($result['opened']);
            $this->assertNotNull($result['exception_case_id']);
            $this->exceptionCaseIds[] = (int) $result['exception_case_id'];

            $case = ExceptionCase::query()->find($result['exception_case_id']);
            $this->assertNotNull($case);
            $this->assertSame($this->typeId(), (int) $case->exception_type_id);
            $this->assertSame((int) $site->getKey(), (int) $case->site_id);
            $this->assertStringContainsString('sscc-batch-#'.$batch->getKey().'-l3-l4-recon', (string) $case->description);
            $this->assertStringContainsString('expected=1', (string) $case->description);
            $this->assertStringContainsString('actual=0', (string) $case->description);
            $this->assertStringContainsString('L4 short', (string) $case->description);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function l4_extra_opens_reconciliation_case(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureExceptionTypes();
            $site = $this->createSite();
            [$batch, $epc] = $this->createCommissionedBatchWithLabel($site);
            $this->authorCommissioningEvent($epc);
            $this->authorCommissioningEvent($epc); // duplicate link → actual=2, expected=1

            $result = app(ReconcileSsccBatchL3L4::class)->handle($batch);

            $this->assertFalse($result['matched']);
            $this->assertSame(1, $result['expected']);
            $this->assertSame(2, $result['actual']);
            $this->assertTrue($result['opened']);
            $this->assertNotNull($result['exception_case_id']);
            $this->exceptionCaseIds[] = (int) $result['exception_case_id'];

            $case = ExceptionCase::query()->find($result['exception_case_id']);
            $this->assertNotNull($case);
            $this->assertStringContainsString('L4 extra', (string) $case->description);
            $this->assertStringContainsString('expected=1', (string) $case->description);
            $this->assertStringContainsString('actual=2', (string) $case->description);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function second_run_keeps_a_single_open_case(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->ensureExceptionTypes();
            $site = $this->createSite();
            [$batch] = $this->createCommissionedBatchWithLabel($site);

            $first = app(ReconcileSsccBatchL3L4::class)->handle($batch);
            $this->assertTrue($first['opened']);
            $this->exceptionCaseIds[] = (int) $first['exception_case_id'];

            $second = app(ReconcileSsccBatchL3L4::class)->handle($batch);
            $this->assertFalse($second['matched']);
            $this->assertFalse($second['opened']);
            $this->assertSame($first['exception_case_id'], $second['exception_case_id']);

            $openCount = ExceptionCase::query()
                ->where('exception_type_id', $this->typeId())
                ->where('description', 'like', '%sscc-batch-#'.$batch->getKey().'-l3-l4-recon%')
                ->whereNotIn('status', [
                    ExceptionStatus::Resolved->value,
                    ExceptionStatus::Closed->value,
                    ExceptionStatus::Cancelled->value,
                ])
                ->count();

            $this->assertSame(1, $openCount);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{0: SsccLabelBatch, 1: Epc}
     */
    private function createCommissionedBatchWithLabel(Site $site): array
    {
        $built = app(SsccBuilder::class)->build('0399991', random_int(100000, 999999), 0);

        $batch = SsccLabelBatch::query()->create([
            'company_prefix' => '0399991',
            'extension_digit' => '0',
            'allocation_mode' => SsccAllocationMode::Sequential,
            'label_count' => 1,
            'copies_per_label' => 1,
            'status' => SsccLabelBatchStatus::Completed,
            'commission_site_id' => $site->getKey(),
            'commissioned_at' => now(),
            'commissioning_epcis_file_path' => 'epcis/outbound/recon-test-'.Str::random(6).'.xml',
        ]);
        $this->batchIds[] = (int) $batch->getKey();

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
            'label_path' => 'labels/sscc/recon-'.$built['sscc_18'].'.pdf',
            'commissioned_at' => now(),
        ]);
        $this->labelIds[] = (int) $label->getKey();

        $epc = Epc::query()->firstOrCreate(
            ['epc_uri' => $built['sscc_urn']],
            Epc::materializeAttributesFromUri($built['sscc_urn']),
        );
        $this->epcIds[] = (int) $epc->getKey();

        return [$batch->fresh(), $epc];
    }

    private function authorCommissioningEvent(Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'recon-commission-'.Str::random(6).'.xml',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'ADD',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
            'disposition' => 'urn:epcglobal:cbv:disp:active',
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function createSite(): Site
    {
        $site = Site::query()->create([
            'name' => 'Reconcile Site '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => true,
            'is_organization_facility' => true,
            'trading_partner_id' => null,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return $site;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $check = 0;
            for ($i = 0; $i < 12; $i++) {
                $check += (int) $body[$i] * (($i % 2 === 0) ? 1 : 3);
            }
            $gln = $body.(string) ((10 - ($check % 10)) % 10);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function typeId(): int
    {
        return (int) ExceptionType::query()
            ->where('code', ReconcileSsccBatchL3L4::EXCEPTION_CODE)
            ->value('id');
    }

    private function ensureExceptionTypes(): void
    {
        (new ExceptionTypeSeeder)->run();
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo 2',
                'profile' => TenantProfile::Manufacturer,
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
        $this->priorProfile = $tenant->profile;
        $tenant->forceFill(['profile' => TenantProfile::Manufacturer])->save();

        TenantSettings::forTenant($tenant)->saveOrganization([
            'gln' => '0399991000008',
            'company_prefix' => '0399991',
            'l3_enabled' => false,
            'l3_endpoint_url' => null,
        ]);

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }

        if ($this->exceptionCaseIds !== []) {
            DB::table('exception_epcs')->whereIn('exception_id', $this->exceptionCaseIds)->delete();
            ExceptionCase::query()->whereIn('id', $this->exceptionCaseIds)->delete();
            $this->exceptionCaseIds = [];
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->documentIds !== []) {
            $eventIds = EpcisEvent::query()
                ->whereIn('document_id', $this->documentIds)
                ->pluck('id')
                ->all();
            if ($eventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                EpcisEvent::query()->whereIn('id', $eventIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->labelIds !== []) {
            DB::table('sscc_label_children')->whereIn('sscc_label_id', $this->labelIds)->delete();
            SsccLabel::query()->whereIn('id', $this->labelIds)->delete();
            $this->labelIds = [];
        }

        if ($this->batchIds !== []) {
            SsccLabelBatch::query()->whereIn('id', $this->batchIds)->delete();
            $this->batchIds = [];
        }

        if ($this->epcIds !== []) {
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            if (DB::getSchemaBuilder()->hasTable('exception_epcs')) {
                DB::table('exception_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
            $this->priorProfile = null;
        }

        tenancy()->end();
    }
}
