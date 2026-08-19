<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeSearchEngine;
use Tests\TestCase;

class ProcessEpcisDocumentScoutIndexTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function happy_path_indexes_events_once(): void
    {
        $this->initializeDemo2Tenant();

        $engine = new FakeSearchEngine([]);
        $this->swapSearchEngine($engine);

        try {
            $document = $this->ingestFixtureDocument();
            $eventIds = $this->eventIdsForDocument($document);

            $this->assertNotEmpty($eventIds);

            $indexedCounts = array_count_values($engine->indexed);
            foreach ($eventIds as $eventId) {
                $this->assertSame(1, $indexedCounts[$eventId] ?? 0, "Event {$eventId} should be indexed exactly once.");
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function ingest_failure_after_event_persist_does_not_index_orphan_generation(): void
    {
        $this->initializeDemo2Tenant();

        $engine = new FakeSearchEngine([]);
        $this->swapSearchEngine($engine);

        $this->app->bind(ValidateEpcis12Document::class, fn () => new class
        {
            public function handle(EpcisDocument $document, ?string $absolutePath = null): void
            {
                throw new RuntimeException('Forced post-persist ingest failure for Scout test.');
            }
        });

        try {
            $maxEventIdBefore = (int) (EpcisEvent::query()->max('id') ?? 0);

            $document = $this->ingestFixtureDocument();

            $this->assertSame('error', $document->fresh()->status);

            $newlyIndexedEventIds = array_values(array_filter(
                $engine->indexed,
                static fn (mixed $id): bool => (int) $id > $maxEventIdBefore,
            ));
            $this->assertSame([], $newlyIndexedEventIds, 'Failed ingest must not index failed-generation events.');
            $this->assertNotEmpty($engine->removed, 'Failed-generation events should be removed from Scout.');

            $this->assertNotSame([], $this->eventIdsForDocument($document), 'Failed first ingest must keep generation-1 rows for the document view.');
        } finally {
            $this->cleanup();
        }
    }

    private function ingestFixtureDocument(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_scout_fail_').'.xml';
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
        file_put_contents($tmp, $xml);

        $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
            'direction' => 'inbound',
            'original_filename' => 'scout-finally-index.xml',
            'dispatch' => false,
        ]);
        $this->documentId = (int) $document->getKey();

        try {
            app(EpcisIngestionService::class)->process($document);
        } catch (RuntimeException) {
            // Expected when ValidateEpcis12Document is mocked to throw.
        }

        return $document->refresh();
    }

    /**
     * @return list<int>
     */
    private function eventIdsForDocument(EpcisDocument $document): array
    {
        return EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->all();
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

    private function swapSearchEngine(FakeSearchEngine $engine): void
    {
        $this->app->extend(EngineManager::class, function (EngineManager $manager) use ($engine): EngineManager {
            $manager->extend('fake-scout-probe', fn (): Engine => $engine);

            return $manager;
        });

        config(['scout.driver' => 'fake-scout-probe']);
        $this->app->forgetInstance(EngineManager::class);
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized && $this->documentId !== null) {
            EpcisEvent::query()->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
