<?php

namespace Tests\Feature\Auth;

use App\Actions\Epcis\SearchEpcisSchema;
use App\Actions\Fda3911\MarkFda3911Submitted;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\Fda3911Classification;
use App\Enums\Fda3911ReportStatus;
use App\Enums\TenantProfile;
use App\Filament\App\Pages\Quarantine;
use App\Filament\App\Pages\UnpackedItems;
use App\Filament\App\Resources\EpcisDocuments\Pages\ListEpcisDocuments;
use App\Filament\App\Resources\Fda3911Reports\Fda3911ReportResource;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Fda3911Report;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\Packing\UnpackedNotRepackedQuery;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteAccessWave2Test extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $reportIds = [];

    /** @var list<int> */
    private array $holdIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if ($this->holdIds !== []) {
                QuarantineHold::query()->whereIn('id', $this->holdIds)->delete();
            }
            if ($this->reportIds !== []) {
                Fda3911Report::query()->whereIn('id', $this->reportIds)->delete();
            }
            if ($this->caseIds !== []) {
                ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            }
            if ($this->eventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
                EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            if ($this->epcIds !== []) {
                Epc::query()->whereIn('id', $this->epcIds)->delete();
            }
            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function fda_3911_list_and_submit_are_scoped_to_exception_site(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $typeId = $this->exceptionTypeId();

            $caseA = $this->createExceptionCase($typeId, siteId: (int) $siteA->id);
            $caseB = $this->createExceptionCase($typeId, siteId: (int) $siteB->id);

            $reportA = $this->createFda3911Report($caseA);
            $reportB = $this->createFda3911Report($caseB);

            $restricted = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($restricted);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $visible = Fda3911ReportResource::getEloquentQuery()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $reportA->id, $visible);
            $this->assertNotContains((int) $reportB->id, $visible);
            $this->assertTrue(Fda3911ReportResource::canView($reportA));
            $this->assertFalse(Fda3911ReportResource::canView($reportB));
            $this->assertTrue(Fda3911ReportResource::canEdit($reportA));
            $this->assertFalse(Fda3911ReportResource::canEdit($reportB));

            $this->expectException(AuthorizationException::class);
            app(MarkFda3911Submitted::class)->execute($reportB, $restricted);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function find_recall_rejects_foreign_document_ids(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $docA = $this->createInboundDocument((int) $siteA->id);
            $docB = $this->createInboundDocument((int) $siteB->id);

            $restricted = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($restricted);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $visibleA = app(SearchEpcisSchema::class)->handle(
                'documents',
                [['field' => 'doc.id', 'operator' => 'eq', 'value' => (int) $docA->id]],
                actor: $restricted,
            );
            $visibleB = app(SearchEpcisSchema::class)->handle(
                'documents',
                [['field' => 'doc.id', 'operator' => 'eq', 'value' => (int) $docB->id]],
                actor: $restricted,
            );

            $this->assertSame(1, $visibleA['total']);
            $this->assertSame((int) $docA->id, (int) $visibleA['rows']->first()->id);
            $this->assertSame(0, $visibleB['total']);

            $component = Livewire::test(ListEpcisDocuments::class);
            $component->call('viewUnitsFromDocument', (int) $docB->id);

            $this->assertNull($component->instance()->schemaSearchPayload);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function quarantine_includes_site_id_only_holds_for_restricted_users(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $typeId = $this->exceptionTypeId();
            $caseA = $this->createExceptionCase($typeId, siteId: (int) $siteA->id);
            $caseB = $this->createExceptionCase($typeId, siteId: (int) $siteB->id);

            $epcA = $this->createEpc('qsitea');
            $epcB = $this->createEpc('qsiteb');
            $holdA = $this->createOpenHold($epcA, $caseA);
            $holdB = $this->createOpenHold($epcB, $caseB);

            $restricted = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($restricted);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $component = Livewire::test(Quarantine::class);
            $method = new \ReflectionMethod(Quarantine::class, 'openHoldsQuery');
            $visible = $method->invoke($component->instance())
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $holdA->id, $visible);
            $this->assertNotContains((int) $holdB->id, $visible);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    #[Test]
    public function unpacked_items_stay_scoped_when_site_filter_is_cleared(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $epcA = $this->seedUnpackedEpcAtSite($siteA);
            $epcB = $this->seedUnpackedEpcAtSite($siteB);

            $restricted = $this->createUserWithSites([(int) $siteA->id]);
            $this->actingAs($restricted);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $unscoped = UnpackedNotRepackedQuery::builder()
                ->pluck('epcs.id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->assertContains((int) $epcA->id, $unscoped);
            $this->assertContains((int) $epcB->id, $unscoped);

            $component = Livewire::test(UnpackedItems::class);
            $query = $component->instance()->getFilteredTableQuery();
            $this->assertNotNull($query);
            $visible = $query->pluck('epcs.id')->map(fn ($id): int => (int) $id)->all();

            $this->assertContains((int) $epcA->id, $visible);
            $this->assertNotContains((int) $epcB->id, $visible);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'Wave2 Site A '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Wave2 Site B '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $this->siteIds = [(int) $siteA->id, (int) $siteB->id];

        return [$siteA, $siteB];
    }

    private function createInboundDocument(int $shipToSiteId): EpcisDocument
    {
        $document = new EpcisDocument;
        $document->forceFill([
            'document_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'status' => 'parsed',
            'format' => 'xml',
            'creation_date' => now(),
            'received_at' => now(),
            'ship_to_site_id' => $shipToSiteId,
            'original_filename' => 'wave2-'.Str::random(6).'.xml',
            'payload_disk' => 'local',
            'payload_path' => 'tests/wave2-'.Str::random(6).'.xml',
            'file_sha256' => hash('sha256', Str::random(32)),
            'ingest_generation' => 1,
        ]);
        $document->save();
        $this->documentIds[] = (int) $document->id;

        return $document;
    }

    private function createExceptionCase(int $typeId, ?int $siteId = null, ?int $documentId = null): ExceptionCase
    {
        $case = ExceptionCase::query()->create([
            'exception_type_id' => $typeId,
            'site_id' => $siteId,
            'document_id' => $documentId,
            'title' => 'Wave2 case '.Str::random(4),
            'description' => 'Wave2',
            'severity' => ExceptionSeverity::Medium,
            'status' => ExceptionStatus::New,
        ]);
        $this->caseIds[] = (int) $case->id;

        return $case;
    }

    private function createFda3911Report(ExceptionCase $case): Fda3911Report
    {
        $report = Fda3911Report::query()->create([
            'status' => Fda3911ReportStatus::Draft,
            'classification' => Fda3911Classification::Illegitimate,
            'exception_id' => $case->id,
            'product_name' => 'Wave2 product',
            'circumstances' => 'Wave2 circumstances',
            'due_at' => now()->addHours(24),
        ]);
        $this->reportIds[] = (int) $report->id;

        return $report;
    }

    private function createEpc(string $serialSuffix): Epc
    {
        $serial = 'w2'.$serialSuffix.Str::lower(Str::random(4));
        $epc = Epc::query()->create([
            'epc_type' => 'sgtin',
            'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.'.$serial,
            'gtin14' => '00301162001162',
            'serial_number' => $serial,
            'company_prefix' => '030116',
            'first_seen_at' => now(),
        ]);
        $this->epcIds[] = (int) $epc->id;

        return $epc;
    }

    private function createOpenHold(Epc $epc, ExceptionCase $case): QuarantineHold
    {
        $hold = QuarantineHold::query()->create([
            'epc_id' => $epc->id,
            'exception_id' => $case->id,
            'reason' => 'Wave2 site_id-only hold',
            'status' => 'open',
            'severity' => 'warning',
            'opened_at' => now(),
        ]);
        $this->holdIds[] = (int) $hold->id;

        return $hold;
    }

    private function seedUnpackedEpcAtSite(Site $site): Epc
    {
        $epc = $this->createEpc('up'.substr((string) $site->id, -3));
        $document = $this->createInboundDocument((int) $site->id);

        $unpack = EpcisEvent::query()->create([
            'document_id' => $document->id,
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'AggregationEvent',
            'event_time' => now()->subMinute(),
            'record_time' => now()->subMinute(),
            'event_timezone_offset' => '+00:00',
            'action' => 'DELETE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:unpacking',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
        ]);
        $this->eventIds[] = (int) $unpack->id;
        DB::table('event_epcs')->insert([
            'event_id' => $unpack->id,
            'epc_id' => $epc->id,
            'role' => 'childEPC',
        ]);

        $receive = EpcisEvent::query()->create([
            'document_id' => $document->id,
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
        $this->eventIds[] = (int) $receive->id;
        DB::table('event_epcs')->insert([
            'event_id' => $receive->id,
            'epc_id' => $epc->id,
            'role' => 'epcList',
        ]);

        return $epc;
    }

    private function exceptionTypeId(): int
    {
        $typeId = ExceptionType::query()->value('id');
        if ($typeId !== null) {
            return (int) $typeId;
        }

        return (int) ExceptionType::query()->create([
            'code' => 'wave2_site_'.Str::lower(Str::random(4)),
            'name' => 'Wave2 site type',
            'is_active' => true,
        ])->id;
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $this->userIds[] = (int) $user->id;

        return $user;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
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
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }
}
