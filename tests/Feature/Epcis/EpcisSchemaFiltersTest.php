<?php

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Filament\App\Resources\EpcisDocuments\Pages\ListEpcisDocuments;
use App\Filament\App\Resources\EpcisDocuments\Pages\ViewEpcisDocument;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\EpcsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\EventsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\ExceptionsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\Tables\EpcisDocumentsTable;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Models\TradingPartner;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisSchemaFiltersTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function list_table_registers_schema_filters(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $table = EpcisDocumentsTable::configure(Table::make(new ListEpcisDocuments));
            $filterNames = collect($table->getFilters())->keys()->all();

            foreach ([
                'status',
                'trading_partner_id',
                'ship_to_partner_id',
                'ship_from_gln',
                'ship_to_gln',
                'sender_gln',
                'receiver_gln',
                'asn_number',
                'lot_number',
                'gtin14',
                'customer_po',
                'dscsa_affirm',
                'creation_date',
                'received_at',
            ] as $expected) {
                $this->assertContains($expected, $filterNames, "Missing filter [{$expected}]");
            }
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function list_filters_constrain_by_status_partner_and_asn(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partnerA = TradingPartner::query()->create([
                'name' => 'Filter Partner A',
                'gln' => '1111111111111',
                'partner_type' => 'wholesaler',
                'is_active' => true,
            ]);
            $partnerB = TradingPartner::query()->create([
                'name' => 'Filter Partner B',
                'gln' => '2222222222222',
                'partner_type' => 'wholesaler',
                'is_active' => true,
            ]);
            $this->partnerIds = [(int) $partnerA->id, (int) $partnerB->id];

            $match = $this->makeDocument([
                'status' => 'parsed',
                'trading_partner_id' => $partnerA->id,
                'asn_number' => 'ASN-FILTER-100',
                'direction' => 'inbound',
            ]);
            $otherStatus = $this->makeDocument([
                'status' => 'error',
                'trading_partner_id' => $partnerA->id,
                'asn_number' => 'ASN-FILTER-100',
                'direction' => 'inbound',
            ]);
            $otherPartner = $this->makeDocument([
                'status' => 'parsed',
                'trading_partner_id' => $partnerB->id,
                'asn_number' => 'ASN-FILTER-100',
                'direction' => 'inbound',
            ]);
            $otherAsn = $this->makeDocument([
                'status' => 'parsed',
                'trading_partner_id' => $partnerA->id,
                'asn_number' => 'ASN-OTHER-999',
                'direction' => 'inbound',
            ]);

            $ids = EpcisDocument::query()
                ->where('status', 'parsed')
                ->where('trading_partner_id', $partnerA->id)
                ->where(function ($query): void {
                    $query->where('asn_number', 'ASN-FILTER-100')
                        ->orWhere('asn_number', 'like', 'ASN-FILTER-100%');
                })
                ->whereIn('id', [$match->id, $otherStatus->id, $otherPartner->id, $otherAsn->id])
                ->pluck('id')
                ->all();

            $this->assertSame([(int) $match->id], array_map('intval', $ids));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function events_relation_manager_exposes_event_filters(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument(['status' => 'parsed', 'direction' => 'inbound']);
            EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            ]);
            EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 1,
                'event_type' => 'AggregationEvent',
                'event_time' => now(),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
            ]);

            $document->forceFill(['ingest_generation' => 1, 'event_count' => 2])->save();

            $manager = app(EventsRelationManager::class);
            $manager->pageClass = ViewEpcisDocument::class;
            $manager->ownerRecord = $document;

            $table = $manager->table(Table::make($manager));
            $filterNames = collect($table->getFilters())->keys()->all();

            $this->assertContains('event_type', $filterNames);
            $this->assertContains('action', $filterNames);
            $this->assertContains('biz_step', $filterNames);
            $this->assertContains('disposition', $filterNames);

            $filtered = $document->activeEvents()->where('event_type', 'ObjectEvent')->count();
            $this->assertSame(1, $filtered);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function epcs_lot_filter_matches_ilmd_rows(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument(['status' => 'parsed', 'direction' => 'inbound']);

            $match = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.filterlot1',
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => 'filterlot1',
                'ai_01_21' => '010030116200116221filterlot1',
                'first_seen_at' => now(),
            ]);
            $other = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.filterlot2',
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => 'filterlot2',
                'ai_01_21' => '010030116200116221filterlot2',
                'first_seen_at' => now(),
            ]);
            $this->epcIds = [(int) $match->id, (int) $other->id];

            EpcIlmd::query()->create([
                'epc_id' => $match->id,
                'lot_number' => 'LOT-FILTER-A',
                'gtin14' => '00301162001162',
            ]);
            EpcIlmd::query()->create([
                'epc_id' => $other->id,
                'lot_number' => 'LOT-FILTER-B',
                'gtin14' => '00301162001162',
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    ['document_id' => $document->id, 'epc_id' => $match->id, 'ingest_generation' => 1],
                    ['document_id' => $document->id, 'epc_id' => $other->id, 'ingest_generation' => 1],
                ]);
            }

            $document->forceFill(['ingest_generation' => 1, 'epc_count' => 2])->save();

            $manager = app(EpcsRelationManager::class);
            $manager->pageClass = ViewEpcisDocument::class;
            $manager->ownerRecord = $document;

            $table = $manager->table(Table::make($manager));
            $filterNames = collect($table->getFilters())->keys()->all();
            $this->assertContains('lot_number', $filterNames);
            $this->assertContains('gtin14', $filterNames);
            $this->assertContains('expiry_date', $filterNames);

            $ids = $document->epcsQuery()
                ->whereHas('ilmd', fn ($q) => $q->where('lot_number', 'LOT-FILTER-A'))
                ->pluck('epcs.id')
                ->all();

            $this->assertSame([(int) $match->id], array_map('intval', $ids));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function exceptions_relation_manager_has_typed_filters(): void
    {
        $source = file_get_contents(app_path('Filament/App/Resources/EpcisDocuments/RelationManagers/ExceptionsRelationManager.php'));
        $this->assertNotFalse($source);
        $this->assertStringContainsString("'open' => 'Open'", $source);
        $this->assertStringContainsString("'severity'", $source);

        // Type filter is searchable and sourced from the live ExceptionType catalog
        // (falling back to the static validation catalog), not a hardcoded flat list.
        $this->assertStringContainsString("SelectFilter::make('exception_type')", $source);
        $this->assertStringContainsString('GroupDocumentExceptionSignals', $source);
        $this->assertStringContainsString('gtin_display', $source);
        $this->assertStringContainsString('->searchable()', $source);
        $this->assertStringContainsString('ExceptionType::query()', $source);
        $this->assertStringContainsString('EpcisValidationCatalog::all()', $source);

        // Legacy pre-catalog lowercase keys must remain filterable so old rows aren't stranded.
        foreach ([
            'ingest_failure',
            'missing_transaction_statement',
            'dropped_epc_uris',
            'atp_soft_warning',
            'sbdh_source_owning_party_mismatch',
            'missing_biz_transaction',
            'incomplete_product_master_data',
        ] as $legacyKey) {
            $this->assertStringContainsString($legacyKey, $source, "Expected legacy exception_type key [{$legacyKey}] to remain filterable");
        }
    }

    #[Test]
    public function exception_type_filter_options_are_grouped_and_include_legacy_keys(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $manager = app(ExceptionsRelationManager::class);
            $document = $this->makeDocument(['status' => 'parsed', 'direction' => 'inbound']);
            $manager->pageClass = ViewEpcisDocument::class;
            $manager->ownerRecord = $document;

            $table = $manager->table(Table::make($manager));
            $filters = collect($table->getFilters())->keyBy(fn ($filter) => $filter->getName());

            $this->assertTrue($filters->has('exception_type'));

            $typeFilter = $filters->get('exception_type');
            $this->assertNotFalse($typeFilter->getSearchable());

            $options = $typeFilter->getOptions();
            $this->assertArrayHasKey('Legacy (pre-catalog)', $options);
            $this->assertArrayHasKey('atp_soft_warning', $options['Legacy (pre-catalog)']);
            $this->assertArrayHasKey('missing_biz_transaction', $options['Legacy (pre-catalog)']);

            // Either the live ExceptionType catalog (grouped by category) or the static
            // validation catalog fallback group must be present alongside "Legacy".
            $hasCatalogGroup = collect($options)
                ->except('Legacy (pre-catalog)')
                ->flatMap(fn (array $group): array => array_keys($group))
                ->contains('MISSING_DSCSA_STATEMENT');
            $this->assertTrue($hasCatalogGroup, 'Expected a catalog-derived group containing MISSING_DSCSA_STATEMENT');
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDocument(array $attributes): EpcisDocument
    {
        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'filter-test.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/filter-test-'.str()->uuid().'.xml',
            'dscsa_affirm' => false,
            'status' => 'received',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'ingest_generation' => 1,
        ], $attributes));

        $this->documentIds[] = (int) $document->id;

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
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $id) {
            EpcIlmd::query()->where('epc_id', $id)->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('epc_id', $id)->delete();
            }
            if (! DB::table('event_epcs')->where('epc_id', $id)->exists()) {
                Epc::query()->whereKey($id)->delete();
            }
        }
        $this->epcIds = [];

        foreach ($this->partnerIds as $id) {
            TradingPartner::query()->whereKey($id)->delete();
        }
        $this->partnerIds = [];

        tenancy()->end();
    }
}
