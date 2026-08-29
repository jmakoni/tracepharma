<?php

namespace Tests\Feature\Custody;

use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Services\Custody\ResolveEpcCustodyAsOf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveEpcCustodyAsOfTest extends TestCase
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

    #[Test]
    public function pre_ship_as_of_reports_commissioned_active(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $epc = $this->createSgtinEpc();
            $commissionAt = Carbon::parse('2026-08-01 10:00:00', 'UTC');
            $shipAt = Carbon::parse('2026-08-01 16:00:00', 'UTC');
            $asOf = Carbon::parse('2026-08-01 12:00:00', 'UTC');

            $this->authorObjectEvent(
                $epc,
                eventTime: $commissionAt,
                action: 'ADD',
                bizStep: 'urn:epcglobal:cbv:bizstep:commissioning',
                disposition: 'urn:epcglobal:cbv:disp:active',
            );
            $this->authorObjectEvent(
                $epc,
                eventTime: $shipAt,
                action: 'OBSERVE',
                bizStep: 'urn:epcglobal:cbv:bizstep:shipping',
                disposition: 'urn:epcglobal:cbv:disp:in_transit',
            );

            $snapshot = app(ResolveEpcCustodyAsOf::class)->handle($epc, $asOf);

            $this->assertTrue($snapshot['found']);
            $this->assertSame('Commissioned', $snapshot['status']);
            $this->assertStringContainsString('active', (string) $snapshot['disposition']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function post_decommission_as_of_reports_terminal_disposition(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $epc = $this->createSgtinEpc();
            $commissionAt = Carbon::parse('2026-08-02 09:00:00', 'UTC');
            $decommissionAt = Carbon::parse('2026-08-02 14:00:00', 'UTC');
            $asOf = Carbon::parse('2026-08-02 15:00:00', 'UTC');

            $this->authorObjectEvent(
                $epc,
                eventTime: $commissionAt,
                action: 'ADD',
                bizStep: 'urn:epcglobal:cbv:bizstep:commissioning',
                disposition: 'urn:epcglobal:cbv:disp:active',
            );
            $this->authorObjectEvent(
                $epc,
                eventTime: $decommissionAt,
                action: 'DELETE',
                bizStep: 'urn:epcglobal:cbv:bizstep:decommissioning',
                disposition: 'urn:epcglobal:cbv:disp:destroyed',
            );

            $snapshot = app(ResolveEpcCustodyAsOf::class)->handle($epc, $asOf);

            $this->assertTrue($snapshot['found']);
            $this->assertSame('Destroyed', $snapshot['status']);
            $this->assertSame('destroyed', $snapshot['disposition']);
            $this->assertStringContainsString('destroyed', (string) $snapshot['disposition_uri']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function superseded_generation_answers_when_as_of_falls_in_its_window(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $epc = $this->createSgtinEpc();
            $doc = $this->createDocument(ingestGeneration: 2);

            $gen1Event = $this->authorObjectEvent(
                $epc,
                eventTime: Carbon::parse('2026-08-03 10:00:00', 'UTC'),
                action: 'OBSERVE',
                bizStep: 'urn:epcglobal:cbv:bizstep:shipping',
                disposition: 'urn:epcglobal:cbv:disp:in_transit',
                document: $doc,
                ingestGeneration: 1,
                readPointGln: '0399991000008',
            );
            $gen1Event->forceFill([
                'superseded_at' => Carbon::parse('2026-08-03 18:00:00', 'UTC'),
                'superseded_by_generation' => 2,
            ])->save();

            $this->authorObjectEvent(
                $epc,
                eventTime: Carbon::parse('2026-08-03 10:00:00', 'UTC'),
                action: 'OBSERVE',
                bizStep: 'urn:epcglobal:cbv:bizstep:receiving',
                disposition: 'urn:epcglobal:cbv:disp:in_progress',
                document: $doc,
                ingestGeneration: 2,
                readPointGln: '0399991000015',
            );

            $inGen1Window = app(ResolveEpcCustodyAsOf::class)->handle(
                $epc,
                Carbon::parse('2026-08-03 12:00:00', 'UTC'),
            );
            $this->assertSame(1, $inGen1Window['ingest_generation']);
            $this->assertSame('In transit', $inGen1Window['status']);
            $this->assertSame('0399991000008', $inGen1Window['gln']);

            $afterSupersede = app(ResolveEpcCustodyAsOf::class)->handle(
                $epc,
                Carbon::parse('2026-08-03 19:00:00', 'UTC'),
            );
            $this->assertSame(2, $afterSupersede['ingest_generation']);
            $this->assertSame('0399991000015', $afterSupersede['gln']);
            $this->assertStringContainsString('receiving', strtolower((string) $afterSupersede['biz_step']));
        } finally {
            $this->cleanup();
        }
    }

    private function createSgtinEpc(): Epc
    {
        $serial = (string) random_int(100000000, 999999999);
        $uri = 'urn:epc:id:sgtin:0399991.000001.'.$serial;
        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createDocument(int $ingestGeneration = 1): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'as-of-'.Str::random(6).'.xml',
            'ingest_generation' => $ingestGeneration,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function authorObjectEvent(
        Epc $epc,
        Carbon $eventTime,
        string $action,
        string $bizStep,
        string $disposition,
        ?EpcisDocument $document = null,
        int $ingestGeneration = 1,
        ?string $readPointGln = null,
    ): EpcisEvent {
        $document ??= $this->createDocument($ingestGeneration);

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => $eventTime,
            'record_time' => $eventTime,
            'event_timezone_offset' => '+00:00',
            'action' => $action,
            'biz_step' => $bizStep,
            'disposition' => $disposition,
            'read_point_gln' => $readPointGln,
            'biz_location_gln' => $readPointGln,
            'ingest_generation' => $ingestGeneration,
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);

        return $event;
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo 2',
                'profile' => TenantProfile::DrugWholesaler,
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

    private function cleanup(): void
    {
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

        if ($this->epcIds !== []) {
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        tenancy()->end();
    }
}
