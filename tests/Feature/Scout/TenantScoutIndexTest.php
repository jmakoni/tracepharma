<?php

declare(strict_types=1);

namespace Tests\Feature\Scout;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\Scout\TenantModelSearch;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSearchEngine;
use Tests\TestCase;

class TenantScoutIndexTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'collection']);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if ($this->eventIds !== []) {
                EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            }

            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }

            if ($this->productIds !== []) {
                Product::query()->whereIn('id', $this->productIds)->delete();
            }

            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            }

            tenancy()->end();
        }

        $this->productIds = [];
        $this->partnerIds = [];
        $this->documentIds = [];
        $this->eventIds = [];

        parent::tearDown();
    }

    #[Test]
    public function tenant_models_use_per_tenant_scout_index_names(): void
    {
        $this->initializeDemo2Tenant();

        $product = $this->createPartnerProduct('Index Name Probe');
        $partner = TradingPartner::query()->findOrFail($product->tradingPartners()->first()->getKey());
        $document = $this->createDocument($partner, 'probe-doc-uuid-001');

        $this->assertSame(
            self::DEMO2_TENANT_ID.'_products',
            $product->searchableAs(),
        );
        $this->assertSame(
            self::DEMO2_TENANT_ID.'_trading_partners',
            $partner->searchableAs(),
        );
        $this->assertSame(
            self::DEMO2_TENANT_ID.'_epcis_documents',
            $document->searchableAs(),
        );
        $this->assertSame(
            self::DEMO2_TENANT_ID.'_epcis_events',
            (new EpcisEvent)->searchableAs(),
        );
    }

    #[Test]
    public function to_searchable_array_includes_tenant_metadata_and_useful_fields(): void
    {
        $this->initializeDemo2Tenant();

        $product = $this->createPartnerProduct('Scout Product Alpha', ndc: '12345-678-90');
        $partner = TradingPartner::query()->findOrFail($product->tradingPartners()->first()->getKey());
        $partner->update([
            'name' => 'Scout Partner Alpha',
            'doing_business_as' => 'SPA DBA',
            'gln' => '5556667778888',
            'partner_type' => PartnerType::Wholesaler,
        ]);
        $document = $this->createDocument($partner, 'doc-uuid-scout-001', filename: 'asn-001.xml', status: 'processed');

        $freshProduct = $product->fresh();

        $this->assertSame([
            'tenant_id' => self::DEMO2_TENANT_ID,
            'name' => 'Scout Product Alpha',
            'gtin' => $freshProduct->gtin,
            'ndc' => '12345-678-90',
            'ndc11' => $freshProduct->ndc11,
            'package_ndc' => $freshProduct->package_ndc,
            'dosage_form' => $freshProduct->dosage_form,
            'strength' => $freshProduct->strength,
            'is_active' => true,
        ], $freshProduct->toSearchableArray());

        $partnerPayload = $partner->fresh()->toSearchableArray();
        $this->assertSame(self::DEMO2_TENANT_ID, $partnerPayload['tenant_id']);
        $this->assertSame('Scout Partner Alpha', $partnerPayload['name']);
        $this->assertSame('SPA DBA', $partnerPayload['doing_business_as']);
        $this->assertSame('5556667778888', $partnerPayload['gln']);

        $documentPayload = $document->fresh()->load('tradingPartner')->toSearchableArray();
        $this->assertSame(self::DEMO2_TENANT_ID, $documentPayload['tenant_id']);
        $this->assertSame('doc-uuid-scout-001', $documentPayload['document_uuid']);
        $this->assertSame('asn-001.xml', $documentPayload['original_filename']);
        $this->assertSame('processed', $documentPayload['status']);
        $this->assertSame('Scout Partner Alpha', $documentPayload['trading_partner_name']);

        $event = $this->createEvent($document, 'urn:uuid:scout-event-001');
        $eventPayload = $event->fresh()->toSearchableArray();
        $this->assertSame(self::DEMO2_TENANT_ID, $eventPayload['tenant_id']);
        $this->assertSame('urn:uuid:scout-event-001', $eventPayload['event_id']);
        $this->assertSame('ADD', $eventPayload['action']);
        $this->assertSame((int) $document->getKey(), $eventPayload['document_id']);
    }

    #[Test]
    public function collection_driver_finds_indexed_tenant_products(): void
    {
        $this->initializeDemo2Tenant();

        $product = $this->createPartnerProduct('Meilisearch Stand-In Product');
        $product->searchable();

        $results = Product::search('Meilisearch')->get();

        $this->assertTrue($results->contains(fn (Product $row): bool => $row->is($product)));
    }

    #[Test]
    public function apply_scout_keys_skips_scout_when_tenancy_is_not_initialized(): void
    {
        $engine = new FakeSearchEngine(null);
        $this->swapSearchEngine($engine);

        $applied = TenantModelSearch::applyScoutKeys(Product::query(), Product::class, 'anything');

        $this->assertFalse($applied);
        $this->assertSame([], $engine->indexed);
    }

    #[Test]
    public function tenant_model_search_falls_back_to_sql_when_index_is_unavailable(): void
    {
        $this->initializeDemo2Tenant();
        $this->swapSearchEngine(new FakeSearchEngine(null));

        $product = $this->createPartnerProduct('Sql Fallback Product');

        $query = Product::query()->whereKey($product->getKey());
        TenantModelSearch::constrain($query, Product::class, 'Sql Fallback', ['name']);

        $this->assertTrue($query->exists());
    }

    #[Test]
    public function trading_partner_create_survives_when_search_index_is_unavailable(): void
    {
        $this->initializeDemo2Tenant();
        $this->swapSearchEngine(new FakeSearchEngine(null));

        $partner = TradingPartner::factory()->create([
            'name' => 'Xttrium Index Outage',
            'partner_type' => PartnerType::Manufacturer,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        $this->assertTrue(
            TradingPartner::query()->whereKey($partner->getKey())->exists(),
        );
    }

    #[Test]
    public function epcis_document_ingest_batches_scout_indexing_for_events(): void
    {
        $this->initializeDemo2Tenant();

        $engine = new FakeSearchEngine([]);
        $this->swapSearchEngine($engine);

        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_scout_').'.xml';
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
        file_put_contents($tmp, $xml);

        try {
            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'scout-batch-index.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            app(EpcisIngestionService::class)->process($document);

            $eventIds = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->pluck('id')
                ->all();

            $this->assertNotEmpty($eventIds);
            foreach ($eventIds as $eventId) {
                $this->assertContains($eventId, $engine->indexed);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    #[Test]
    public function scout_reindex_command_imports_tenant_models(): void
    {
        $this->initializeDemo2Tenant();

        $product = $this->createPartnerProduct('Reindex Command Product');
        $partner = TradingPartner::query()->findOrFail($product->tradingPartners()->first()->getKey());
        $document = $this->createDocument($partner, 'reindex-doc-uuid', filename: 'reindex.xml');
        $event = $this->createEvent($document, 'urn:uuid:reindex-event-001');

        Product::removeAllFromSearch();
        TradingPartner::removeAllFromSearch();
        EpcisDocument::removeAllFromSearch();
        EpcisEvent::removeAllFromSearch();

        $this->artisan('tracepharma:scout-reindex', [
            '--tenant' => self::DEMO2_TENANT_ID,
        ])->assertSuccessful();

        $this->assertTrue(
            Product::search('Reindex Command')->get()->contains(fn (Product $row): bool => $row->is($product)),
        );
        $this->assertTrue(
            EpcisEvent::search('reindex-event')->get()->contains(fn (EpcisEvent $row): bool => $row->is($event)),
        );
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

    private function createPartnerProduct(
        string $name,
        ?string $gtin = null,
        ?string $ndc = null,
    ): Product {
        $gtin ??= fake()->unique()->numerify('##############');

        $partner = TradingPartner::withoutSyncingToSearch(fn () => TradingPartner::factory()->create([
            'name' => 'Partner for '.$name,
            'partner_type' => PartnerType::Wholesaler,
        ]));
        $this->partnerIds[] = (int) $partner->getKey();

        $product = Product::withoutSyncingToSearch(fn () => Product::factory()->create([
            'name' => $name,
            'gtin' => $gtin,
            'ndc' => $ndc,
            'is_active' => true,
        ]));
        $this->productIds[] = (int) $product->getKey();

        $partner->products()->attach($product->getKey(), [
            'authorization_status' => 'authorized',
            'authorized_at' => now(),
            'is_primary' => true,
        ]);

        return $product;
    }

    private function createDocument(
        TradingPartner $partner,
        string $documentUuid,
        string $filename = 'test.xml',
        string $status = 'received',
    ): EpcisDocument {
        $document = EpcisDocument::withoutSyncingToSearch(fn () => EpcisDocument::query()->create([
            'document_uuid' => $documentUuid,
            'creation_date' => now(),
            'direction' => 'inbound',
            'trading_partner_id' => $partner->getKey(),
            'original_filename' => $filename,
            'status' => $status,
            'received_at' => now(),
        ]));
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function createEvent(EpcisDocument $document, string $eventId): EpcisEvent
    {
        $event = EpcisEvent::withoutSyncingToSearch(fn () => EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => $eventId,
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'record_time' => now(),
            'action' => 'ADD',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            'read_point_gln' => '0614141000001',
        ]));
        $this->eventIds[] = (int) $event->getKey();

        return $event;
    }

    private function swapSearchEngine(FakeSearchEngine $engine): void
    {
        $this->app->extend(EngineManager::class, function (EngineManager $manager) use ($engine): EngineManager {
            $manager->extend('fake-unavailable', fn (): Engine => $engine);

            return $manager;
        });

        config(['scout.driver' => 'fake-unavailable']);
        $this->app->forgetInstance(EngineManager::class);
    }
}
