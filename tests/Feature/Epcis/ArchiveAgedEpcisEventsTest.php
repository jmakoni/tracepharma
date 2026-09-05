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
            $document = EpcisDocument::query()->find($documentId);
            $this->assertNotNull($document, 'Event archive must not delete the epcis_documents row (pedigree payload pointer).');
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

            $tableEvents = app(BuildAssetTrace::class)->eventsForTrackingTable($epc->fresh())['records'];
            $this->assertEqualsCanonicalizing(
                $this->eventIds,
                $tableEvents->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            );

            $mid = Carbon::parse($commissionAt)->utc()->addMinutes(30);
            $asOfTable = app(BuildAssetTrace::class)->eventsForTrackingTable($epc->fresh(), $mid)['records'];
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
            $tableEvents = app(BuildAssetTrace::class)->eventsForTrackingTable($epc->fresh())['records'];
            $this->assertFalse(
                $tableEvents->contains(fn (EpcisEvent $row): bool => (int) $row->getKey() === $eventId),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function as_of_tracking_table_excludes_archived_error_and_voided_documents(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $epc = $this->createSgtinEpc();

            $good = $this->authorObjectEvent(
                $epc,
                eventTime: now()->subYears(2)->addHour(),
                action: 'ADD',
                bizStep: 'urn:epcglobal:cbv:bizstep:commissioning',
                disposition: 'urn:epcglobal:cbv:disp:active',
            );
            $goodId = (int) $good->getKey();

            $voided = $this->authorObjectEvent(
                $epc,
                eventTime: now()->subYears(2)->addHours(2),
                action: 'OBSERVE',
                bizStep: 'urn:epcglobal:cbv:bizstep:shipping',
                disposition: 'urn:epcglobal:cbv:disp:in_transit',
            );
            $voidedId = (int) $voided->getKey();
            EpcisDocument::query()->whereKey($voided->document_id)->update([
                'status' => 'voided',
                'processed_at' => now()->subYears(2),
            ]);

            $error = $this->authorObjectEvent(
                $epc,
                eventTime: now()->subYears(2)->addHours(3),
                action: 'OBSERVE',
                bizStep: 'urn:epcglobal:cbv:bizstep:receiving',
                disposition: 'urn:epcglobal:cbv:disp:in_progress',
            );
            $errorId = (int) $error->getKey();
            EpcisDocument::query()->whereKey($error->document_id)->update([
                'status' => 'error',
                'processed_at' => now()->subYears(2),
            ]);

            app(ArchiveAgedEpcisEvents::class)->handle();

            $this->assertDatabaseHas('epcis_events_archive', ['id' => $goodId]);
            $this->assertDatabaseHas('epcis_events_archive', ['id' => $voidedId]);
            $this->assertDatabaseHas('epcis_events_archive', ['id' => $errorId]);

            $asOf = Carbon::parse($good->event_time)->utc()->addDays(1);
            $tableEvents = app(BuildAssetTrace::class)->eventsForTrackingTable($epc->fresh(), $asOf)['records'];
            $ids = $tableEvents->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

            $this->assertContains($goodId, $ids);
            $this->assertNotContains($voidedId, $ids);
            $this->assertNotContains($errorId, $ids);
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

    /**
     * Finding 3 — dual hot+archive aged rows must be cleaned by orphan pass (hot deleted, archive kept).
     */
    #[Test]
    public function orphan_pass_deletes_hot_when_aged_event_already_in_archive(): void
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

            DB::table('event_biz_transactions')->insert([
                'event_id' => $oldId,
                'type_uri' => 'urn:epcglobal:cbv:btt:desadv',
                'value' => 'ASN-ORPHAN-001',
            ]);

            $this->seedArchiveCopyOfHotEvent($oldId);

            $this->assertDatabaseHas('epcis_events', ['id' => $oldId]);
            $this->assertDatabaseHas('epcis_events_archive', ['id' => $oldId]);
            $this->assertDatabaseHas('event_epcs', ['event_id' => $oldId]);
            $this->assertDatabaseHas('event_epcs_archive', ['event_id' => $oldId]);
            $this->assertDatabaseHas('event_biz_transactions_archive', [
                'event_id' => $oldId,
                'value' => 'ASN-ORPHAN-001',
            ]);

            $result = app(ArchiveAgedEpcisEvents::class)->handle();

            $this->assertSame(0, $result['would_archive']);
            $this->assertSame(0, $result['archived']);
            $this->assertSame(1, $result['would_delete_orphans']);
            $this->assertSame(1, $result['deleted_orphans']);

            $this->assertDatabaseMissing('epcis_events', ['id' => $oldId]);
            $this->assertDatabaseMissing('event_epcs', ['event_id' => $oldId]);
            $this->assertDatabaseMissing('event_biz_transactions', ['event_id' => $oldId]);
            $this->assertDatabaseHas('epcis_events_archive', ['id' => $oldId]);
            $this->assertDatabaseHas('event_epcs_archive', [
                'event_id' => $oldId,
                'epc_id' => $epc->getKey(),
            ]);
            $this->assertDatabaseHas('event_biz_transactions_archive', [
                'event_id' => $oldId,
                'value' => 'ASN-ORPHAN-001',
            ]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dry_run_counts_would_delete_orphans_without_deleting(): void
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
            $this->seedArchiveCopyOfHotEvent($oldId);

            $result = app(ArchiveAgedEpcisEvents::class)->handle(dryRun: true);

            $this->assertSame(0, $result['would_archive']);
            $this->assertSame(0, $result['archived']);
            $this->assertSame(1, $result['would_delete_orphans']);
            $this->assertSame(0, $result['deleted_orphans']);
            $this->assertDatabaseHas('epcis_events', ['id' => $oldId]);
            $this->assertDatabaseHas('epcis_events_archive', ['id' => $oldId]);
            $this->assertDatabaseHas('event_epcs', ['event_id' => $oldId]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function orphan_pass_refuses_incomplete_archive_children(): void
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

            DB::table('event_biz_transactions')->insert([
                'event_id' => $oldId,
                'type_uri' => 'urn:epcglobal:cbv:btt:desadv',
                'value' => 'ASN-INCOMPLETE-ORPHAN',
            ]);

            // Archive event + event_epcs only — leave biz_transactions hot-only (incomplete).
            $this->seedArchiveCopyOfHotEvent($oldId, includeBizTransactions: false);

            try {
                app(ArchiveAgedEpcisEvents::class)->handle();
                $this->fail('Expected incomplete orphan archive to abort hot delete.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('event_biz_transactions', $exception->getMessage());
            }

            $this->assertDatabaseHas('epcis_events', ['id' => $oldId]);
            $this->assertDatabaseHas('event_biz_transactions', [
                'event_id' => $oldId,
                'value' => 'ASN-INCOMPLETE-ORPHAN',
            ]);
            $this->assertDatabaseHas('epcis_events_archive', ['id' => $oldId]);
            $this->assertDatabaseMissing('event_biz_transactions_archive', ['event_id' => $oldId]);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Finding 5 — archive MOVE must handle aggregation_links established by aged events
     * (copy to archive; clear hot established_by_event_id so hierarchy stays without dangling FK).
     */
    #[Test]
    public function archive_move_copies_aggregation_links_and_nullifies_hot_event_fk(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.epcis.retention_years' => 1]);
            $this->assertTrue(
                DB::getSchemaBuilder()->hasTable('aggregation_links_archive'),
                'aggregation_links_archive must exist for archive MOVE',
            );

            $parent = $this->createSgtinEpc();
            $child = $this->createSgtinEpc();
            $event = $this->authorObjectEvent(
                $parent,
                eventTime: now()->subYears(2),
                action: 'ADD',
                bizStep: 'urn:epcglobal:cbv:bizstep:packing',
                disposition: 'urn:epcglobal:cbv:disp:in_progress',
            );
            $eventId = (int) $event->getKey();

            EpcisEvent::query()->whereKey($eventId)->update(['event_type' => 'AggregationEvent']);

            $linkId = (int) DB::table('aggregation_links')->insertGetId([
                'parent_epc_id' => $parent->getKey(),
                'child_epc_id' => $child->getKey(),
                'established_by_event_id' => $eventId,
                'link_type' => 'aggregation',
                'valid_from' => now()->subYears(2)->format('Y-m-d H:i:s.u'),
                'valid_to' => null,
                'created_at' => now()->format('Y-m-d H:i:s.u'),
            ]);

            app(ArchiveAgedEpcisEvents::class)->handle();

            $this->assertDatabaseMissing('epcis_events', ['id' => $eventId]);
            $this->assertDatabaseHas('epcis_events_archive', ['id' => $eventId]);
            $this->assertDatabaseHas('aggregation_links_archive', [
                'id' => $linkId,
                'parent_epc_id' => $parent->getKey(),
                'child_epc_id' => $child->getKey(),
                'established_by_event_id' => $eventId,
            ]);
            $this->assertDatabaseHas('aggregation_links', [
                'id' => $linkId,
                'parent_epc_id' => $parent->getKey(),
                'child_epc_id' => $child->getKey(),
                'established_by_event_id' => null,
            ]);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Simulate a failed MOVE that left dual hot+archive rows (event + children already copied).
     */
    private function seedArchiveCopyOfHotEvent(int $eventId, bool $includeBizTransactions = true): void
    {
        $eventColumns = array_values(array_intersect(
            DB::getSchemaBuilder()->getColumnListing('epcis_events'),
            array_diff(DB::getSchemaBuilder()->getColumnListing('epcis_events_archive'), ['archived_at']),
        ));
        $quoted = implode(', ', array_map(fn (string $column): string => '`'.$column.'`', $eventColumns));
        DB::insert(
            "INSERT IGNORE INTO epcis_events_archive ({$quoted}, archived_at) SELECT {$quoted}, ? FROM epcis_events WHERE id = ?",
            [now(), $eventId],
        );

        foreach ([
            'event_epcs' => 'event_epcs_archive',
            'event_parties' => 'event_parties_archive',
            'event_locations' => 'event_locations_archive',
            'event_quantities' => 'event_quantities_archive',
            'event_epc_ilmd' => 'event_epc_ilmd_archive',
        ] as $hot => $archive) {
            if (! DB::getSchemaBuilder()->hasTable($hot) || ! DB::getSchemaBuilder()->hasTable($archive)) {
                continue;
            }
            $columns = array_values(array_intersect(
                DB::getSchemaBuilder()->getColumnListing($hot),
                DB::getSchemaBuilder()->getColumnListing($archive),
            ));
            if ($columns === []) {
                continue;
            }
            $childQuoted = implode(', ', array_map(fn (string $column): string => '`'.$column.'`', $columns));
            DB::insert(
                "INSERT IGNORE INTO {$archive} ({$childQuoted}) SELECT {$childQuoted} FROM {$hot} WHERE event_id = ?",
                [$eventId],
            );
        }

        if ($includeBizTransactions
            && DB::getSchemaBuilder()->hasTable('event_biz_transactions')
            && DB::getSchemaBuilder()->hasTable('event_biz_transactions_archive')
        ) {
            $columns = array_values(array_intersect(
                DB::getSchemaBuilder()->getColumnListing('event_biz_transactions'),
                DB::getSchemaBuilder()->getColumnListing('event_biz_transactions_archive'),
            ));
            if ($columns !== []) {
                $childQuoted = implode(', ', array_map(fn (string $column): string => '`'.$column.'`', $columns));
                DB::insert(
                    "INSERT IGNORE INTO event_biz_transactions_archive ({$childQuoted}) SELECT {$childQuoted} FROM event_biz_transactions WHERE event_id = ?",
                    [$eventId],
                );
            }
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
            if (DB::getSchemaBuilder()->hasTable('aggregation_links_archive')) {
                DB::table('aggregation_links_archive')->whereIn('established_by_event_id', $this->eventIds)->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('aggregation_links')) {
                DB::table('aggregation_links')->whereIn('established_by_event_id', $this->eventIds)->delete();
                DB::table('aggregation_links')
                    ->whereIn('parent_epc_id', $this->epcIds)
                    ->orWhereIn('child_epc_id', $this->epcIds)
                    ->delete();
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
            if (DB::getSchemaBuilder()->hasTable('aggregation_links')) {
                DB::table('aggregation_links')
                    ->whereIn('parent_epc_id', $this->epcIds)
                    ->orWhereIn('child_epc_id', $this->epcIds)
                    ->delete();
            }
            if (DB::getSchemaBuilder()->hasTable('aggregation_links_archive')) {
                DB::table('aggregation_links_archive')
                    ->whereIn('parent_epc_id', $this->epcIds)
                    ->orWhereIn('child_epc_id', $this->epcIds)
                    ->delete();
            }
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
