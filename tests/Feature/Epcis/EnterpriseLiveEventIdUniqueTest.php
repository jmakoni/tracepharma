<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ProcessEpcisDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnterpriseLiveEventIdUniqueTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function second_live_insert_with_same_event_id_fails(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $eventId = 'urn:uuid:'.(string) Str::uuid();
            $first = $this->createInboundDocument();
            $second = $this->createInboundDocument();

            EpcisEvent::query()->create($this->eventAttrs($first, $eventId, generation: 1));

            $this->expectException(QueryException::class);
            EpcisEvent::query()->create($this->eventAttrs($second, $eventId, generation: 1));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function superseded_duplicate_event_id_is_allowed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $eventId = 'urn:uuid:'.(string) Str::uuid();
            $document = $this->createInboundDocument();

            $prior = EpcisEvent::query()->create($this->eventAttrs($document, $eventId, generation: 1));
            $prior->forceFill(['superseded_at' => now(), 'superseded_by_generation' => 2])->save();

            $live = EpcisEvent::query()->create($this->eventAttrs($document, $eventId, generation: 2));

            $this->assertNotNull($live->getKey());
            $this->assertSame($eventId, $live->event_id);
            $this->assertNull($live->superseded_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function null_event_id_still_inserts_twice(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->createInboundDocument();

            $first = EpcisEvent::query()->create($this->eventAttrs($document, null, generation: 1));
            $second = EpcisEvent::query()->create($this->eventAttrs($document, null, generation: 1));

            $this->assertNotSame((int) $first->getKey(), (int) $second->getKey());
            $this->assertNull($first->event_id);
            $this->assertNull($second->event_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function same_document_reprocess_with_event_id_keeps_one_live(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $eventId = 'urn:uuid:'.(string) Str::uuid();
            $document = $this->createInboundDocument();
            EpcisEvent::query()->create($this->eventAttrs($document, $eventId, generation: 1));

            app(ProcessEpcisDocument::class)->persistParsedDocument($document->fresh(), [
                'events' => [[
                    'event_type' => 'ObjectEvent',
                    'event_id' => $eventId,
                    'event_time' => now()->subHour()->toIso8601String(),
                    'action' => 'ADD',
                    'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                    'disposition' => 'urn:epcglobal:cbv:disp:active',
                    'epcs' => [],
                ]],
                'product_classes' => [],
                'locations' => [],
            ]);

            $reprocessed = $document->fresh();

            $live = EpcisEvent::query()
                ->where('event_id', $eventId)
                ->when(
                    Schema::hasColumn('epcis_events', 'superseded_at'),
                    fn ($query) => $query->whereNull('superseded_at'),
                )
                ->get();

            $this->assertCount(1, $live);
            $this->assertSame((int) $reprocessed->getKey(), (int) $live->first()?->document_id);
            $this->assertGreaterThan(1, (int) $reprocessed->ingest_generation);

            $prior = EpcisEvent::query()
                ->where('document_id', $reprocessed->getKey())
                ->where('event_id', $eventId)
                ->where('ingest_generation', 1)
                ->first();

            $this->assertNotNull($prior);
            $this->assertNotNull($prior->superseded_at);
            $this->assertSame(1, (int) $reprocessed->event_count);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function persist_parsed_replay_does_not_count_skipped_event(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $eventId = 'urn:uuid:'.(string) Str::uuid();
            $accepted = $this->createInboundDocument();
            EpcisEvent::query()->create($this->eventAttrs($accepted, $eventId, generation: 1));

            $replay = $this->createInboundDocument();
            app(ProcessEpcisDocument::class)->persistParsedDocument($replay->fresh(), [
                'events' => [[
                    'event_type' => 'ObjectEvent',
                    'event_id' => $eventId,
                    'event_time' => now()->subHour()->toIso8601String(),
                    'action' => 'ADD',
                    'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                    'disposition' => 'urn:epcglobal:cbv:disp:active',
                    'epcs' => [],
                ]],
                'product_classes' => [],
                'locations' => [],
            ]);

            $this->assertSame(0, (int) $replay->fresh()->event_count);
            $this->assertSame(
                0,
                EpcisEvent::query()->where('document_id', $replay->getKey())->count(),
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function eventAttrs(EpcisDocument $document, ?string $eventId, int $generation): array
    {
        return [
            'document_id' => $document->getKey(),
            'ingest_generation' => $generation,
            'event_id' => $eventId,
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subHour(),
            'action' => 'ADD',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
            'disposition' => 'urn:epcglobal:cbv:disp:active',
        ];
    }

    private function createInboundDocument(): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'status' => 'validated',
            'original_filename' => 'live-unique-'.Str::random(6).'.xml',
            'received_at' => now(),
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
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
        if (tenancy()->initialized && $this->documentIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', function ($query): void {
                $query->select('id')->from('epcis_events')->whereIn('document_id', $this->documentIds);
            })->delete();
            EpcisEvent::query()->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
