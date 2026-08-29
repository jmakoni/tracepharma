<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ArchiveAgedEpcisEvents;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisEventArchive;
use App\Models\Tenant;
use App\Services\Custody\ResolveEpcCustodyAsOf;
use App\Services\Tracing\BuildAssetTrace;
use App\Support\Epcis\ArchivedEpcEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArchiveAgedEpcisEventsTest extends TestCase
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
    public function old_event_is_archived_not_deleted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $epc = $this->createSgtinEpc();
            $old = $this->authorObjectEvent(
                $epc,
                eventTime: now()->subYears(2),
                action: 'ADD',
                bizStep: 'urn:epcglobal:cbv:bizstep:commissioning',
                disposition: 'urn:epcglobal:cbv:disp:active',
            );
            $oldId = (int) $old->getKey();
            $documentId = (int) $old->document_id;

            app(ArchiveAgedEpcisEvents::class)->handle();

            $this->assertDatabaseMissing('epcis_events', ['id' => $oldId]);
            $this->assertDatabaseHas('epcis_events_archive', ['id' => $oldId]);
            $this->assertDatabaseHas('event_epcs_archive', [
                'event_id' => $oldId,
                'epc_id' => $epc->getKey(),
            ]);
            $this->assertNotNull(Epc::query()->find($epc->getKey()));
            $this->assertNotNull(EpcisDocument::query()->find($documentId));
            $this->assertNotNull(EpcisEventArchive::query()->find($oldId)?->archived_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function archive_move_preserves_ti_ts_children_and_hydrates_them(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $epc = $this->createSgtinEpc();
            $event = $this->authorObjectEvent(
                $epc,
                eventTime: now()->subYears(2),
                action: 'OBSERVE',
                bizStep: 'urn:epcglobal:cbv:bizstep:shipping',
                disposition: 'urn:epcglobal:cbv:disp:in_transit',
            );
            $eventId = (int) $event->getKey();

            DB::table('event_biz_transactions')->insert([
                'event_id' => $eventId,
                'type_uri' => 'urn:epcglobal:cbv:btt:desadv',
                'value' => 'ASN-ARCHIVE-CHILD-001',
            ]);
            DB::table('event_parties')->insert([
                'event_id' => $eventId,
                'party_role' => 'soldTo',
                'gln' => '0614141123452',
                'gln_uri' => null,
                'trading_partner_id' => null,
                'site_id' => null,
                'extra_json' => null,
            ]);
            DB::table('event_locations')->insert([
                'event_id' => $eventId,
                'location_type' => 'bizLocation',
                'gln' => '0614141123452',
                'gln_uri' => null,
                'name' => 'Ship From DC',
                'street_address' => null,
                'city' => 'Dallas',
                'state' => 'TX',
                'postal_code' => null,
                'country_code' => 'US',
                'latitude' => null,
                'longitude' => null,
                'site_id' => null,
                'location_device_id' => null,
                'read_point_id' => null,
                'extra_json' => null,
            ]);
            DB::table('event_quantities')->insert([
                'event_id' => $eventId,
                'role' => 'quantityList',
                'epc_class' => 'urn:epc:idpat:sgtin:030116.012345.*',
                'quantity' => 12,
                'uom' => 'EA',
            ]);
            DB::table('event_epc_ilmd')->insert([
                'event_id' => $eventId,
                'epc_id' => $epc->getKey(),
                'lot_number' => 'LOT-ARCH-1',
                'expiry_date' => '2028-01-01',
                'manufacturing_date' => null,
                'best_before_date' => null,
                'additional_id' => null,
                'extra_json' => null,
            ]);

            app(ArchiveAgedEpcisEvents::class)->handle();

            $this->assertDatabaseMissing('epcis_events', ['id' => $eventId]);
            $this->assertDatabaseMissing('event_biz_transactions', ['event_id' => $eventId]);
            $this->assertDatabaseHas('event_biz_transactions_archive', [
                'event_id' => $eventId,
                'value' => 'ASN-ARCHIVE-CHILD-001',
            ]);
            $this->assertDatabaseHas('event_parties_archive', [
                'event_id' => $eventId,
                'party_role' => 'soldTo',
            ]);
            $this->assertDatabaseHas('event_locations_archive', [
                'event_id' => $eventId,
                'location_type' => 'bizLocation',
                'name' => 'Ship From DC',
            ]);
            $this->assertDatabaseHas('event_quantities_archive', [
                'event_id' => $eventId,
                'quantity' => 12,
            ]);
            $this->assertDatabaseHas('event_epc_ilmd_archive', [
                'event_id' => $eventId,
                'lot_number' => 'LOT-ARCH-1',
            ]);

            $hydrated = app(ArchivedEpcEvents::class)->forEpc((int) $epc->getKey());
            $this->assertCount(1, $hydrated);
            $row = $hydrated->first();
            $this->assertNotNull($row);
            $this->assertCount(1, $row->locations);
            $this->assertSame('Ship From DC', $row->locations->first()?->name);
            $this->assertCount(1, $row->bizTransactions);
            $this->assertSame('ASN-ARCHIVE-CHILD-001', $row->bizTransactions->first()?->value);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function recent_event_stays_hot(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $epc = $this->createSgtinEpc();
            $recent = $this->authorObjectEvent(
                $epc,
                eventTime: now(),
                action: 'ADD',
                bizStep: 'urn:epcglobal:cbv:bizstep:commissioning',
                disposition: 'urn:epcglobal:cbv:disp:active',
            );
            $recentId = (int) $recent->getKey();

            app(ArchiveAgedEpcisEvents::class)->handle();

            $this->assertDatabaseHas('epcis_events', ['id' => $recentId]);
            $this->assertDatabaseMissing('epcis_events_archive', ['id' => $recentId]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function as_of_finds_archived_commission_and_ship(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $epc = $this->createSgtinEpc();
            $commissionAt = now()->subYears(2)->subHour();
            $shipAt = now()->subYears(2);

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

            app(ArchiveAgedEpcisEvents::class)->handle();

            $this->assertSame(0, EpcisEvent::query()->whereIn('id', $this->eventIds)->count());

            $snapshot = app(ResolveEpcCustodyAsOf::class)->handle($epc->fresh(), now());
            $this->assertTrue($snapshot['found']);
            $this->assertSame('In transit', $snapshot['status']);
            $this->assertStringContainsString('shipping', (string) $snapshot['biz_step']);

            $trace = app(BuildAssetTrace::class)->handle((string) $epc->epc_uri);
            $this->assertTrue($trace['found']);
            $steps = collect($trace['timeline'])->pluck('business_step')->filter()->all();
            $this->assertContains('commissioning', $steps);
            $this->assertContains('shipping', $steps);

            $tableEvents = app(BuildAssetTrace::class)->eventsForTrackingTable($epc->fresh());
            $this->assertEqualsCanonicalizing(
                $this->eventIds,
                $tableEvents->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            );

            $mid = Carbon::parse($commissionAt)->utc()->addMinutes(30);
            $asOfTable = app(BuildAssetTrace::class)->eventsForTrackingTable($epc->fresh(), $mid);
            $this->assertSame(
                [(int) $this->eventIds[0]],
                $asOfTable->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function tracking_table_excludes_archived_hard_error_documents(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $epc = $this->createSgtinEpc();
            $event = $this->authorObjectEvent(
                $epc,
                eventTime: now()->subYears(2),
                action: 'ADD',
                bizStep: 'urn:epcglobal:cbv:bizstep:commissioning',
                disposition: 'urn:epcglobal:cbv:disp:active',
            );
            $eventId = (int) $event->getKey();
            EpcisDocument::query()->whereKey($event->document_id)->update([
                'status' => 'error',
                'processed_at' => null,
            ]);

            app(ArchiveAgedEpcisEvents::class)->handle();

            $this->assertDatabaseHas('epcis_events_archive', ['id' => $eventId]);
            $tableEvents = app(BuildAssetTrace::class)->eventsForTrackingTable($epc->fresh());
            $this->assertFalse(
                $tableEvents->contains(fn (EpcisEvent $row): bool => (int) $row->getKey() === $eventId),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dry_run_writes_nothing_and_logs_counts_only(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $epc = $this->createSgtinEpc();
            $old = $this->authorObjectEvent(
                $epc,
                eventTime: now()->subYears(2),
                action: 'ADD',
                bizStep: 'urn:epcglobal:cbv:bizstep:commissioning',
                disposition: 'urn:epcglobal:cbv:disp:active',
            );
            $oldId = (int) $old->getKey();
            $hotBefore = EpcisEvent::query()->count();
            $archiveBefore = DB::table('epcis_events_archive')->count();

            $this->artisan('tracepharma:epcis-archive-events', [
                '--tenant' => self::DEMO2_TENANT_ID,
                '--dry-run' => true,
            ])
                ->expectsOutputToContain('would_archive=')
                ->doesntExpectOutputToContain((string) $epc->epc_uri)
                ->assertSuccessful();

            $this->assertSame($hotBefore, EpcisEvent::query()->count());
            $this->assertSame($archiveBefore, (int) DB::table('epcis_events_archive')->count());
            $this->assertDatabaseHas('epcis_events', ['id' => $oldId]);
            $this->assertDatabaseMissing('epcis_events_archive', ['id' => $oldId]);
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

    private function authorObjectEvent(
        Epc $epc,
        Carbon $eventTime,
        string $action,
        string $bizStep,
        string $disposition,
    ): EpcisEvent {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'archive-'.Str::random(6).'.xml',
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->getKey();

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
            'ingest_generation' => 1,
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
            foreach ([
                'event_epc_ilmd_archive',
                'event_quantities_archive',
                'event_biz_transactions_archive',
                'event_locations_archive',
                'event_parties_archive',
                'event_epcs_archive',
            ] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->whereIn('event_id', $this->eventIds)->delete();
                }
            }
            if (DB::getSchemaBuilder()->hasTable('epcis_events_archive')) {
                DB::table('epcis_events_archive')->whereIn('id', $this->eventIds)->delete();
            }
            foreach (['event_epc_ilmd', 'event_quantities', 'event_biz_transactions', 'event_locations', 'event_parties', 'event_epcs'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->whereIn('event_id', $this->eventIds)->delete();
                }
            }
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
            if (DB::getSchemaBuilder()->hasTable('event_epcs_archive')) {
                DB::table('event_epcs_archive')->whereIn('epc_id', $this->epcIds)->delete();
            }
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        tenancy()->end();
    }
}
