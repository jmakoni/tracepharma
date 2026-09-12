<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\LeadershipDscsaPack;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\TransmissionMdn;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Dashboard\LeadershipDscsaPackMetrics;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadershipDscsaPackTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $transmissionMdnIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<string> */
    private array $storagePaths = [];

    #[Test]
    public function transmit_success_metrics_reflect_seeded_outbound_documents(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);

            $metrics = LeadershipDscsaPackMetrics::make($user, 'mtd');
            $baseline = $metrics->transmitSuccess()['summary'];

            $sentDocA = $this->createOutboundDocument(['transmission_status' => 'sent', 'sent_at' => now()]);
            $sentDocB = $this->createOutboundDocument(['transmission_status' => 'sent', 'sent_at' => now()]);
            $failedDoc = $this->createOutboundDocument(['transmission_status' => 'failed', 'sent_at' => now()]);
            $seededIds = [(int) $sentDocA->getKey(), (int) $sentDocB->getKey(), (int) $failedDoc->getKey()];

            $payload = LeadershipDscsaPackMetrics::make($user, 'mtd')->transmitSuccess();
            $summary = $payload['summary'];

            $this->assertSame(($baseline['sent'] ?? 0) + 2, $summary['sent']);
            $this->assertSame(($baseline['failed'] ?? 0) + 1, $summary['failed']);
            $this->assertSame(($baseline['total_scored'] ?? 0) + 3, $summary['total_scored']);

            $seededRows = collect($payload['rows'])->whereIn('document_id', $seededIds);
            $this->assertCount(3, $seededRows);
            $this->assertSame(2, $seededRows->where('transmission_status', 'sent')->count());
            $this->assertSame(1, $seededRows->where('transmission_status', 'failed')->count());
            $this->assertSame(66.7, round((2 / 3) * 100, 1));
            $this->assertSame(
                round(($summary['sent'] / $summary['total_scored']) * 100, 1),
                $summary['percent'],
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function mdn_success_metrics_reflect_seeded_transmission_mdns(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);

            $baseline = LeadershipDscsaPackMetrics::make($user, 'mtd')->mdnSuccess()['summary'];
            $seededMdnIds = [];

            foreach (['received', 'received', 'failed'] as $status) {
                $document = $this->createOutboundDocument();
                $mdn = TransmissionMdn::query()->create([
                    'document_id' => $document->getKey(),
                    'mdn_status' => $status,
                    'mdn_payload' => ['message_id' => 'mdn-'.Str::uuid()],
                ]);
                $seededMdnIds[] = (int) $mdn->getKey();
                $this->transmissionMdnIds[] = (int) $mdn->getKey();
            }

            $payload = LeadershipDscsaPackMetrics::make($user, 'mtd')->mdnSuccess();
            $summary = $payload['summary'];

            $this->assertSame(($baseline['received'] ?? 0) + 2, $summary['received']);
            $this->assertSame(($baseline['failed'] ?? 0) + 1, $summary['failed']);
            $this->assertSame(($baseline['total_scored'] ?? 0) + 3, $summary['total_scored']);

            $seededRows = collect($payload['rows'])->whereIn('mdn_id', $seededMdnIds);
            $this->assertCount(3, $seededRows);
            $this->assertSame(2, $seededRows->where('mdn_status', 'received')->count());
            $this->assertSame(1, $seededRows->where('mdn_status', 'failed')->count());
            $this->assertSame(66.7, round((2 / 3) * 100, 1));
            $this->assertSame(
                round(($summary['received'] / $summary['total_scored']) * 100, 1),
                $summary['percent'],
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function late_missing_mdn_metrics_reflect_pending_mdns_past_sla(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.as2_mdn.missing_after_hours' => 24,
                'tracepharma.as2_mdn.late_after_hours' => 72,
            ]);

            $user = $this->createOwner();
            $this->actingAs($user);

            $missingDoc = $this->createOutboundDocument();
            $missingMdn = TransmissionMdn::query()->create([
                'document_id' => $missingDoc->getKey(),
                'mdn_status' => 'pending',
                'mdn_payload' => ['message_id' => 'missing-'.Str::uuid()],
            ]);
            $missingMdn->forceFill(['created_at' => now()->subHours(30)])->save();
            $this->transmissionMdnIds[] = (int) $missingMdn->getKey();

            $lateDoc = $this->createOutboundDocument();
            $lateMdn = TransmissionMdn::query()->create([
                'document_id' => $lateDoc->getKey(),
                'mdn_status' => 'pending',
                'mdn_payload' => ['message_id' => 'late-'.Str::uuid()],
            ]);
            $lateMdn->forceFill(['created_at' => now()->subHours(80)])->save();
            $this->transmissionMdnIds[] = (int) $lateMdn->getKey();

            $summary = LeadershipDscsaPackMetrics::make($user, 'mtd')->lateMissingMdn()['summary'];

            $this->assertGreaterThanOrEqual(1, $summary['missing_mdn_pending']);
            $this->assertGreaterThanOrEqual(1, $summary['late_mdn_pending']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function stuck_serials_and_open_exceptions_reflect_overdue_missing_mdn_case(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);

            $type = ExceptionType::query()->where('code', 'MISSING_MDN')->first();
            if ($type === null) {
                $this->seed(ExceptionTypeSeeder::class);
                $type = ExceptionType::query()->where('code', 'MISSING_MDN')->firstOrFail();
            }

            $document = $this->createOutboundDocument();

            $epc = Epc::fromUri('urn:epc:id:sgtin:030116.0200116.leadership'.Str::random(8));
            $epc->first_seen_at = now();
            $epc->save();
            $this->epcIds[] = (int) $epc->getKey();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Missing MDN leadership test '.Str::uuid(),
                'description' => 'Awaiting partner MDN',
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::New,
                'due_at' => now()->subHour(),
            ]);
            $case->epcs()->attach($epc->getKey());
            $this->caseIds[] = (int) $case->getKey();

            $metrics = LeadershipDscsaPackMetrics::make($user, 'mtd');

            $stuck = $metrics->stuckSerialsByStatus()['summary'];
            $this->assertGreaterThanOrEqual(1, $stuck['total_epcs']);
            $statuses = collect($stuck['by_status'])->pluck('status')->all();
            $this->assertContains('new', $statuses);

            $byCode = $metrics->openExceptionsByCode()['summary']['by_code'];
            $missingMdnRow = collect($byCode)->firstWhere('code', 'MISSING_MDN');
            $this->assertNotNull($missingMdnRow);
            $this->assertGreaterThanOrEqual(1, $missingMdnRow['count']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function decommission_by_reason_metrics_reflect_destroyed_events(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);

            $document = $this->createOutboundDocument();

            $epc = Epc::fromUri('urn:epc:id:sgtin:030116.0200116.decomm'.Str::random(8));
            $epc->first_seen_at = now();
            $epc->save();
            $this->epcIds[] = (int) $epc->getKey();

            $event = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_id' => 'urn:uuid:'.(string) Str::uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'record_time' => now(),
                'event_timezone_offset' => '+00:00',
                'action' => 'DELETE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:decommissioning',
                'disposition' => 'urn:epcglobal:cbv:disp:destroyed',
                'extension_json' => ['decommission_reason' => 'destroyed'],
                'ingest_generation' => 1,
            ]);
            $this->eventIds[] = (int) $event->getKey();

            DB::table('event_epcs')->insert([
                'event_id' => $event->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ]);

            $summary = LeadershipDscsaPackMetrics::make($user, 'mtd')->decommissionByReason()['summary'];

            $this->assertGreaterThanOrEqual(1, $summary['total']);
            $destroyed = collect($summary['reasons'])->firstWhere('reason', 'destroyed');
            $this->assertNotNull($destroyed);
            $this->assertGreaterThanOrEqual(1, $destroyed['count']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function owner_can_view_leadership_dscsa_pack_page(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);

            $this->assertTrue(LeadershipDscsaPack::canAccess());

            Livewire::test(LeadershipDscsaPack::class)
                ->assertOk()
                ->assertSee('Leadership')
                ->assertSee('Transmit success');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function pack_includes_l3_l4_ingest_lag_section(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);

            $this->assertArrayHasKey(
                'l3_l4_ingest_lag',
                LeadershipDscsaPackMetrics::make($user, 'mtd')->all(),
            );

            Livewire::test(LeadershipDscsaPack::class)
                ->assertOk()
                ->assertSee('L3→L4 ingest lag');
        } finally {
            $this->cleanup();
        }
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
        $user = User::factory()->create([
            'email' => 'owner-leadership-'.Str::uuid().'@example.com',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOutboundDocument(array $overrides = []): EpcisDocument
    {
        $suffix = (string) Str::uuid();
        $path = 'epcis/outbound/leadership-dscsa-'.$suffix.'.xml';
        $xml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
        Storage::disk('local')->put($path, $xml);
        $this->storagePaths[] = $path;

        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'leadership-dscsa-'.$suffix.'.xml',
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $xml),
            'dscsa_affirm' => false,
            'status' => 'parsed',
            'reprocess_count' => 0,
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
        ], $overrides));

        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            if ($tenant->profile !== TenantProfile::DrugWholesaler) {
                $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            }
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        $this->seed(ExceptionTypeSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->caseIds !== []) {
            foreach ($this->caseIds as $caseId) {
                $case = ExceptionCase::query()->find($caseId);
                $case?->epcs()->detach();
                $case?->activities()->delete();
                $case?->delete();
            }
            $this->caseIds = [];
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->transmissionMdnIds !== []) {
            TransmissionMdn::query()->whereIn('id', $this->transmissionMdnIds)->delete();
            $this->transmissionMdnIds = [];
        }

        if ($this->documentIds !== []) {
            TransmissionMdn::query()->whereIn('document_id', $this->documentIds)->delete();
            EpcisEvent::query()->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->epcIds !== []) {
            if (Schema::hasTable('exception_epcs')) {
                DB::table('exception_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            }
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        foreach ($this->storagePaths as $path) {
            Storage::disk('local')->delete($path);
        }
        $this->storagePaths = [];

        tenancy()->end();
    }
}
