<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\PruneSupersededIngestGenerations;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSearchEngine;
use Tests\TestCase;

class PruneSupersededIngestGenerationsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function prune_deletes_superseded_generations_and_keeps_active(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'prune-test.xml',
                'file_sha256' => hash('sha256', (string) str()->uuid()),
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/prune-test-'.str()->uuid().'.xml',
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
                'ingest_generation' => 2,
            ]);
            $this->documentIds[] = (int) $document->id;

            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.prune'.substr((string) str()->uuid(), 0, 8),
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => 'prune'.random_int(100000, 999999),
                'ai_01_21' => '010030116200116221prune'.random_int(1000, 9999),
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $stale = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subHour(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);
            $active = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 2,
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);

            DB::table('event_epcs')->insert([
                ['event_id' => $stale->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
                ['event_id' => $active->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 1],
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 2],
                ]);
            }

            $stats = app(PruneSupersededIngestGenerations::class)->handle($document->fresh());

            $this->assertSame(2, $stats['kept_generation']);
            $this->assertSame(1, $stats['events_deleted']);
            $this->assertSame(1, $stats['document_epcs_deleted']);

            $this->assertFalse(EpcisEvent::query()->whereKey($stale->id)->exists());
            $this->assertTrue(EpcisEvent::query()->whereKey($active->id)->exists());
            $this->assertSame(
                1,
                EpcisEvent::query()->where('document_id', $document->id)->count(),
            );
            $this->assertSame(
                0,
                (int) DB::table('event_epcs')->where('event_id', $stale->id)->count(),
            );

            if (Schema::hasTable('document_epcs')) {
                $this->assertSame(
                    1,
                    (int) DB::table('document_epcs')->where('document_id', $document->id)->count(),
                );
                $this->assertSame(
                    2,
                    (int) DB::table('document_epcs')
                        ->where('document_id', $document->id)
                        ->value('ingest_generation'),
                );
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function prune_orphan_generations_deletes_future_generations_only(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'prune-orphan-test.xml',
                'file_sha256' => hash('sha256', (string) str()->uuid()),
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/prune-orphan-test-'.str()->uuid().'.xml',
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
                'processed_at' => now(),
                'ingest_generation' => 2,
            ]);
            $this->documentIds[] = (int) $document->id;

            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.orphan'.substr((string) str()->uuid(), 0, 8),
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => 'orphan'.random_int(100000, 999999),
                'ai_01_21' => '010030116200116221orphan'.random_int(1000, 9999),
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $superseded = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subHours(2),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);
            $active = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 2,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subHour(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);
            $orphan = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 3,
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);

            DB::table('event_epcs')->insert([
                ['event_id' => $superseded->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
                ['event_id' => $active->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
                ['event_id' => $orphan->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 1],
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 2],
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 3],
                ]);
            }

            $stats = app(PruneSupersededIngestGenerations::class)
                ->pruneOrphanGenerations($document->fresh());

            $this->assertSame(2, $stats['kept_generation']);
            $this->assertSame(1, $stats['events_deleted']);
            $this->assertSame(1, $stats['document_epcs_deleted']);

            $this->assertTrue(EpcisEvent::query()->whereKey($superseded->id)->exists());
            $this->assertTrue(EpcisEvent::query()->whereKey($active->id)->exists());
            $this->assertFalse(EpcisEvent::query()->whereKey($orphan->id)->exists());
            $this->assertSame(
                2,
                EpcisEvent::query()->where('document_id', $document->id)->count(),
            );

            if (Schema::hasTable('document_epcs')) {
                $this->assertSame(
                    2,
                    (int) DB::table('document_epcs')->where('document_id', $document->id)->count(),
                );
                $this->assertSame(
                    [1, 2],
                    DB::table('document_epcs')
                        ->where('document_id', $document->id)
                        ->orderBy('ingest_generation')
                        ->pluck('ingest_generation')
                        ->map(fn ($generation) => (int) $generation)
                        ->all(),
                );
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function prune_orphan_generations_keeps_generation_one_when_first_ingest_never_succeeded(): void
    {
        $this->initializeDemo2Tenant();

        try {
            // Failed first ingest writes events at generation 1. Those rows are the
            // only projection the UI can show — prune must not treat them as orphans.
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'prune-never-confirmed-test.xml',
                'file_sha256' => hash('sha256', (string) str()->uuid()),
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/prune-never-confirmed-test-'.str()->uuid().'.xml',
                'dscsa_affirm' => false,
                'status' => 'error',
                'ingest_generation' => 1,
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->id;

            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.neverok'.substr((string) str()->uuid(), 0, 8),
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => 'neverok'.random_int(100000, 999999),
                'ai_01_21' => '010030116200116221neverok'.random_int(1000, 9999),
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $partial = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);

            DB::table('event_epcs')->insert([
                ['event_id' => $partial->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 1],
                ]);
            }

            $stats = app(PruneSupersededIngestGenerations::class)
                ->pruneOrphanGenerations($document->fresh());

            $this->assertSame(1, $stats['kept_generation']);
            $this->assertSame(0, $stats['events_deleted']);
            $this->assertTrue(EpcisEvent::query()->whereKey($partial->id)->exists());
            $this->assertSame(
                1,
                EpcisEvent::query()->where('document_id', $document->id)->count(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function handle_prunes_superseded_and_orphan_generations(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'prune-all-test.xml',
                'file_sha256' => hash('sha256', (string) str()->uuid()),
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/prune-all-test-'.str()->uuid().'.xml',
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
                'ingest_generation' => 2,
            ]);
            $this->documentIds[] = (int) $document->id;

            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.pruneall'.substr((string) str()->uuid(), 0, 8),
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => 'pruneall'.random_int(100000, 999999),
                'ai_01_21' => '010030116200116221pruneall'.random_int(1000, 9999),
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $superseded = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subHours(2),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);
            $active = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 2,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subHour(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);
            $orphan = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 3,
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);

            DB::table('event_epcs')->insert([
                ['event_id' => $superseded->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
                ['event_id' => $active->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
                ['event_id' => $orphan->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 1],
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 2],
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 3],
                ]);
            }

            $stats = app(PruneSupersededIngestGenerations::class)->handle($document->fresh());

            $this->assertSame(2, $stats['kept_generation']);
            $this->assertSame(2, $stats['events_deleted']);
            $this->assertSame(2, $stats['document_epcs_deleted']);

            $this->assertFalse(EpcisEvent::query()->whereKey($superseded->id)->exists());
            $this->assertTrue(EpcisEvent::query()->whereKey($active->id)->exists());
            $this->assertFalse(EpcisEvent::query()->whereKey($orphan->id)->exists());
            $this->assertSame(
                1,
                EpcisEvent::query()->where('document_id', $document->id)->count(),
            );
            $this->assertSame(
                0,
                (int) DB::table('event_epcs')->where('event_id', $superseded->id)->count(),
            );
            $this->assertSame(
                0,
                (int) DB::table('event_epcs')->where('event_id', $orphan->id)->count(),
            );

            if (Schema::hasTable('document_epcs')) {
                $this->assertSame(
                    1,
                    (int) DB::table('document_epcs')->where('document_id', $document->id)->count(),
                );
                $this->assertSame(
                    2,
                    (int) DB::table('document_epcs')
                        ->where('document_id', $document->id)
                        ->value('ingest_generation'),
                );
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function prune_removes_superseded_events_from_scout(): void
    {
        $this->initializeDemo2Tenant();

        $engine = new FakeSearchEngine([]);
        $this->swapSearchEngine($engine);

        try {
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'prune-scout-test.xml',
                'file_sha256' => hash('sha256', (string) str()->uuid()),
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/prune-scout-test-'.str()->uuid().'.xml',
                'dscsa_affirm' => false,
                'status' => 'parsed',
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
                'ingest_generation' => 2,
            ]);
            $this->documentIds[] = (int) $document->id;

            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.scout'.substr((string) str()->uuid(), 0, 8),
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => 'scout'.random_int(100000, 999999),
                'ai_01_21' => '010030116200116221scout'.random_int(1000, 9999),
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            $stale = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subHour(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);
            $active = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 2,
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);

            DB::table('event_epcs')->insert([
                ['event_id' => $stale->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
                ['event_id' => $active->id, 'epc_id' => $epc->id, 'role' => 'epcList'],
            ]);

            EpcisEvent::query()->whereIn('id', [$stale->id, $active->id])->searchable();

            app(PruneSupersededIngestGenerations::class)->handle($document->fresh());

            $this->assertContains((int) $stale->id, $engine->removed, 'Superseded event must be removed from Scout');
            $this->assertNotContains((int) $active->id, $engine->removed, 'Active generation event must stay indexed');
            $this->assertFalse(EpcisEvent::query()->whereKey($stale->id)->exists());
            $this->assertTrue(EpcisEvent::query()->whereKey($active->id)->exists());
        } finally {
            $this->cleanup();
        }
    }

    private function swapSearchEngine(FakeSearchEngine $engine): void
    {
        $this->app->extend(EngineManager::class, function (EngineManager $manager) use ($engine): EngineManager {
            $manager->extend('fake-scout-probe', fn (): Engine => $engine);

            return $manager;
        });

        config(['scout.driver' => 'fake-scout-probe']);
        $this->app->forgetInstance(EngineManager::class);
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

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $id) {
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('epc_id', $id)->delete();
            }
            if (! DB::table('event_epcs')->where('epc_id', $id)->exists()) {
                Epc::query()->whereKey($id)->delete();
            }
        }
        $this->epcIds = [];

        tenancy()->end();
    }
}
