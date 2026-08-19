<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\RecallEpcsByGtinLot;
use App\Actions\Epcis\SearchEpcisSchema;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EventBizTransaction;
use App\Models\Tenant;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SearchEpcisSchemaTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    #[Test]
    public function epc_search_without_selective_key_is_rejected(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('Add a product or shipment identifier (GTIN, lot, SSCC, ASN, or PO).');

            app(SearchEpcisSchema::class)->handle('epcs', [
                ['field' => 'doc.status', 'operator' => 'eq', 'value' => 'parsed'],
                ['field' => 'ilmd.expiry_date', 'operator' => 'after', 'value' => '2020-01-01'],
            ]);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function unknown_field_is_rejected(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->expectException(InvalidArgumentException::class);

            app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.notes', 'operator' => 'eq', 'value' => 'x'],
            ]);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function gtin_and_lot_finds_matching_epcs(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $gtin = '00301162001162';
            $lot = 'LOT-SEARCH-'.$suffix;

            $match = $this->makeEpc([
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.search{$suffix}a",
                'gtin14' => $gtin,
                'serial_number' => "search{$suffix}a",
                'ai_01_21' => "010030116200116221search{$suffix}a",
            ]);
            $otherLot = $this->makeEpc([
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.search{$suffix}b",
                'gtin14' => $gtin,
                'serial_number' => "search{$suffix}b",
                'ai_01_21' => "010030116200116221search{$suffix}b",
            ]);

            $this->makeIlmd($match, $gtin, $lot);
            $this->makeIlmd($otherLot, $gtin, 'LOT-OTHER-'.$suffix);

            $result = app(SearchEpcisSchema::class)->handle('epcs', [
                ['field' => 'epc.gtin14', 'operator' => 'eq', 'value' => $gtin],
                ['field' => 'ilmd.lot_number', 'operator' => 'eq', 'value' => $lot],
            ]);

            $this->assertSame('epcs', $result['type']);
            $this->assertSame(1, $result['total']);
            $this->assertFalse($result['truncated']);
            $this->assertSame([(int) $match->id], $result['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());

            $viaRecall = app(RecallEpcsByGtinLot::class)->handle($gtin, $lot);
            $this->assertSame([(int) $match->id], $viaRecall->pluck('id')->map(fn ($id) => (int) $id)->all());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function asn_or_po_matches_customer_po_reference(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $po = 'C7174-'.$suffix;
            $asn = 'ASN-'.$suffix;

            $match = $this->makeDocument([
                'asn_number' => $asn,
                'customer_po' => $po,
                'status' => 'parsed',
            ]);
            $this->makeDocument([
                'asn_number' => 'OTHER-'.$suffix,
                'customer_po' => 'OTHER-PO-'.$suffix,
                'status' => 'parsed',
            ]);

            $byPo = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_or_po', 'operator' => 'eq', 'value' => $po],
            ]);
            $byAsn = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_or_po', 'operator' => 'eq', 'value' => $asn],
            ]);

            $this->assertSame(1, $byPo['total']);
            $this->assertSame([(int) $match->id], $byPo['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());
            $this->assertSame(1, $byAsn['total']);
            $this->assertSame([(int) $match->id], $byAsn['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function or_between_lots_matches_either_lot(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $gtin = '00301162001162';
            $lotA = 'LOT-A-'.$suffix;
            $lotB = 'LOT-B-'.$suffix;

            $epcA = $this->makeEpc([
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.ora{$suffix}",
                'gtin14' => $gtin,
                'serial_number' => "ora{$suffix}",
                'ai_01_21' => "010030116200116221ora{$suffix}",
            ]);
            $epcB = $this->makeEpc([
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.orb{$suffix}",
                'gtin14' => $gtin,
                'serial_number' => "orb{$suffix}",
                'ai_01_21' => "010030116200116221orb{$suffix}",
            ]);
            $epcOther = $this->makeEpc([
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.orc{$suffix}",
                'gtin14' => $gtin,
                'serial_number' => "orc{$suffix}",
                'ai_01_21' => "010030116200116221orc{$suffix}",
            ]);

            $this->makeIlmd($epcA, $gtin, $lotA);
            $this->makeIlmd($epcB, $gtin, $lotB);
            $this->makeIlmd($epcOther, $gtin, 'LOT-OTHER-'.$suffix);

            $result = app(SearchEpcisSchema::class)->handle('epcs', [
                ['field' => 'epc.gtin14', 'operator' => 'eq', 'value' => $gtin],
                ['boolean' => 'and', 'field' => 'ilmd.lot_number', 'operator' => 'eq', 'value' => $lotA],
                ['boolean' => 'or', 'field' => 'ilmd.lot_number', 'operator' => 'eq', 'value' => $lotB],
            ]);

            $ids = $result['rows']->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

            $this->assertSame(2, $result['total']);
            $this->assertSame(
                collect([(int) $epcA->id, (int) $epcB->id])->sort()->values()->all(),
                $ids,
            );
            $this->assertNotContains((int) $epcOther->id, $ids);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function asn_document_search_matches_and_logs_activity(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $asn = 'ASN-SEARCH-'.$suffix;

            $match = $this->makeDocument([
                'asn_number' => $asn,
                'status' => 'parsed',
                'direction' => 'inbound',
            ]);
            $this->makeDocument([
                'asn_number' => 'ASN-OTHER-'.$suffix,
                'status' => 'parsed',
                'direction' => 'inbound',
            ]);

            $before = Activity::query()->where('description', 'epcis_schema_search')->count();

            $result = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'eq', 'value' => $asn],
            ]);

            $this->assertSame('documents', $result['type']);
            $this->assertSame(1, $result['total']);
            $this->assertSame([(int) $match->id], $result['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());

            $this->assertSame($before + 1, Activity::query()->where('description', 'epcis_schema_search')->count());

            $activity = Activity::query()
                ->where('description', 'epcis_schema_search')
                ->latest('id')
                ->first();
            $this->assertNotNull($activity);
            $this->assertSame('documents', $activity->properties->get('result_type'));
            $this->assertSame(['doc.asn_number'], $activity->properties->get('fields'));
            $this->assertSame(['eq'], $activity->properties->get('operators'));
            $this->assertSame(1, $activity->properties->get('hit_count'));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function document_asn_supports_neq_ends_with_and_is_empty(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $matchEnds = $this->makeDocument([
                'asn_number' => 'PREFIX-'.$suffix,
                'status' => 'parsed',
            ]);
            $matchEmpty = $this->makeDocument([
                'asn_number' => null,
                'status' => 'parsed',
            ]);
            $other = $this->makeDocument([
                'asn_number' => 'OTHER-NOMATCH-'.$suffix.'X',
                'status' => 'parsed',
            ]);

            $endsWith = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'ends_with', 'value' => $suffix],
            ]);
            $this->assertSame([(int) $matchEnds->id], $endsWith['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());

            $neq = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'neq', 'value' => 'OTHER-NOMATCH-'.$suffix.'X'],
                ['field' => 'doc.status', 'operator' => 'eq', 'value' => 'parsed'],
            ]);
            // Use full id list — display rows are capped and the tenant may have many parsed docs.
            $neqIds = $neq['ids'];
            $this->assertContains((int) $matchEnds->id, $neqIds);
            $this->assertNotContains((int) $other->id, $neqIds);

            $empty = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'is_empty'],
                ['field' => 'doc.status', 'operator' => 'eq', 'value' => 'parsed'],
            ]);
            $this->assertContains((int) $matchEmpty->id, $empty['ids']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function document_status_is_any_of_matches_multiple_values(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $parsed = $this->makeDocument([
                'asn_number' => 'ASN-ANY-'.$suffix,
                'status' => 'parsed',
            ]);
            $error = $this->makeDocument([
                'asn_number' => 'ASN-ANY-'.$suffix,
                'status' => 'error',
            ]);
            $this->makeDocument([
                'asn_number' => 'ASN-ANY-'.$suffix,
                'status' => 'received',
            ]);

            $result = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'eq', 'value' => 'ASN-ANY-'.$suffix],
                ['field' => 'doc.status', 'operator' => 'is_any_of', 'value' => ['parsed', 'error']],
            ]);

            $ids = $result['rows']->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $this->assertSame(
                collect([(int) $parsed->id, (int) $error->id])->sort()->values()->all(),
                $ids,
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function document_dscsa_affirm_is_true_matches(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $affirmed = $this->makeDocument([
                'asn_number' => 'ASN-AFFIRM-'.$suffix,
                'dscsa_affirm' => true,
            ]);
            $this->makeDocument([
                'asn_number' => 'ASN-AFFIRM-'.$suffix,
                'dscsa_affirm' => false,
            ]);

            $result = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'eq', 'value' => 'ASN-AFFIRM-'.$suffix],
                ['field' => 'doc.dscsa_affirm', 'operator' => 'is_true'],
            ]);

            $this->assertSame(1, $result['total']);
            $this->assertSame([(int) $affirmed->id], $result['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function document_creation_date_is_today_matches(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $today = $this->makeDocument([
                'asn_number' => 'ASN-TODAY-'.$suffix,
                'creation_date' => now(),
            ]);
            $this->makeDocument([
                'asn_number' => 'ASN-TODAY-'.$suffix,
                'creation_date' => now()->subDays(3),
            ]);

            $result = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'eq', 'value' => 'ASN-TODAY-'.$suffix],
                ['field' => 'doc.creation_date', 'operator' => 'is_today'],
            ]);

            $this->assertSame(1, $result['total']);
            $this->assertSame([(int) $today->id], $result['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function document_creation_date_date_only_eq_matches_datetime_later_that_day(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $match = $this->makeDocument([
                'asn_number' => 'ASN-DATEEQ-'.$suffix,
                'creation_date' => '2024-06-15 17:45:00',
            ]);
            $this->makeDocument([
                'asn_number' => 'ASN-DATEEQ-'.$suffix,
                'creation_date' => '2024-06-16 01:00:00',
            ]);

            $result = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'eq', 'value' => 'ASN-DATEEQ-'.$suffix],
                ['field' => 'doc.creation_date', 'operator' => 'eq', 'value' => '2024-06-15'],
            ]);

            $this->assertSame(1, $result['total']);
            $this->assertSame([(int) $match->id], $result['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function document_creation_date_before_or_equal_and_not_between(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $early = $this->makeDocument([
                'asn_number' => 'ASN-DATE-'.$suffix,
                'creation_date' => '2024-01-15 12:00:00',
            ]);
            $mid = $this->makeDocument([
                'asn_number' => 'ASN-DATE-'.$suffix,
                'creation_date' => '2024-06-15 12:00:00',
            ]);
            $late = $this->makeDocument([
                'asn_number' => 'ASN-DATE-'.$suffix,
                'creation_date' => '2024-12-15 12:00:00',
            ]);

            $beforeOrEqual = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'eq', 'value' => 'ASN-DATE-'.$suffix],
                ['field' => 'doc.creation_date', 'operator' => 'before_or_equal', 'value' => '2024-06-15 12:00:00'],
            ]);
            $beforeIds = $beforeOrEqual['rows']->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $this->assertSame(
                collect([(int) $early->id, (int) $mid->id])->sort()->values()->all(),
                $beforeIds,
            );

            $notBetween = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.asn_number', 'operator' => 'eq', 'value' => 'ASN-DATE-'.$suffix],
                [
                    'field' => 'doc.creation_date',
                    'operator' => 'not_between',
                    'value' => '2024-03-01',
                    'value_to' => '2024-09-01',
                ],
            ]);
            $notBetweenIds = $notBetween['rows']->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $this->assertSame(
                collect([(int) $early->id, (int) $late->id])->sort()->values()->all(),
                $notBetweenIds,
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function is_empty_operator_works_without_value_key(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $emptyAsn = $this->makeDocument([
                'asn_number' => null,
                'customer_po' => 'PO-EMPTY-'.$suffix,
            ]);
            $this->makeDocument([
                'asn_number' => 'ASN-NONEMPTY-'.$suffix,
                'customer_po' => 'PO-EMPTY-'.$suffix,
            ]);

            $result = app(SearchEpcisSchema::class)->handle('documents', [
                ['field' => 'doc.customer_po', 'operator' => 'eq', 'value' => 'PO-EMPTY-'.$suffix],
                ['field' => 'doc.asn_number', 'operator' => 'is_empty'],
            ]);

            $this->assertSame(1, $result['total']);
            $this->assertSame([(int) $emptyAsn->id], $result['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function doc_id_finds_epcs_linked_to_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $gtin = '00301162001162';
            $lot = 'LOT-DOCID-'.$suffix;

            $epc = $this->makeEpc([
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.docid{$suffix}",
                'gtin14' => $gtin,
                'serial_number' => "docid{$suffix}",
                'ai_01_21' => "010030116200116221docid{$suffix}",
            ]);
            $this->makeIlmd($epc, $gtin, $lot);

            $document = $this->makeDocument(['status' => 'parsed', 'direction' => 'inbound']);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    'document_id' => $document->id,
                    'epc_id' => $epc->id,
                    'ingest_generation' => 1,
                ]);
            }

            $result = app(SearchEpcisSchema::class)->handle('epcs', [
                ['field' => 'doc.id', 'operator' => 'eq', 'value' => $document->id],
            ]);

            $this->assertSame(1, $result['total']);
            $this->assertSame([(int) $epc->id], $result['rows']->pluck('id')->map(fn ($id) => (int) $id)->all());
            $this->assertSame([(int) $epc->id], $result['ids']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function search_returns_full_id_set_separate_from_display_limited_rows(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $gtin = '00301162001162';
            $lot = 'LOT-IDS-'.$suffix;

            $epcA = $this->makeEpc([
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.ids{$suffix}a",
                'gtin14' => $gtin,
                'serial_number' => "ids{$suffix}a",
                'ai_01_21' => "010030116200116221ids{$suffix}a",
            ]);
            $epcB = $this->makeEpc([
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.ids{$suffix}b",
                'gtin14' => $gtin,
                'serial_number' => "ids{$suffix}b",
                'ai_01_21' => "010030116200116221ids{$suffix}b",
            ]);
            $this->makeIlmd($epcA, $gtin, $lot);
            $this->makeIlmd($epcB, $gtin, $lot);

            $result = app(SearchEpcisSchema::class)->handle('epcs', [
                ['field' => 'epc.gtin14', 'operator' => 'eq', 'value' => $gtin],
                ['field' => 'ilmd.lot_number', 'operator' => 'eq', 'value' => $lot],
            ], displayLimit: 1);

            $this->assertSame(2, $result['total']);
            $this->assertCount(1, $result['rows']);
            $this->assertCount(2, $result['ids']);
            $this->assertEqualsCanonicalizing(
                [(int) $epcA->id, (int) $epcB->id],
                $result['ids'],
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function search_surfaces_true_total_when_more_than_limit_matches_exist(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $gtin = '00301162001162';
            $lot = 'LOVF-'.$suffix;
            $matchCount = 1001;

            for ($i = 0; $i < $matchCount; $i++) {
                $serial = 'ovf'.$suffix.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                $epc = $this->makeEpc([
                    'epc_uri' => "urn:epc:id:sgtin:030116.0200116.{$serial}",
                    'gtin14' => $gtin,
                    'serial_number' => $serial,
                    'ai_01_21' => '010030116200116221'.$serial,
                ]);
                $this->makeIlmd($epc, $gtin, $lot);
            }

            $result = app(SearchEpcisSchema::class)->handle('epcs', [
                ['field' => 'epc.gtin14', 'operator' => 'eq', 'value' => $gtin],
                ['field' => 'ilmd.lot_number', 'operator' => 'eq', 'value' => $lot],
            ]);

            $this->assertSame($matchCount, $result['total']);
            $this->assertTrue($result['truncated']);
            $this->assertCount(1000, $result['ids']);
            $this->assertCount(100, $result['rows']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function event_type_exists_filter_does_not_duplicate_epc_rows(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = substr((string) str()->uuid(), 0, 8);
            $gtin = '00301162001162';
            $lot = 'LOT-EVT-'.$suffix;

            $epc = $this->makeEpc([
                'epc_uri' => "urn:epc:id:sgtin:030116.0200116.evt{$suffix}",
                'gtin14' => $gtin,
                'serial_number' => "evt{$suffix}",
                'ai_01_21' => "010030116200116221evt{$suffix}",
            ]);
            $this->makeIlmd($epc, $gtin, $lot);

            $document = $this->makeDocument(['status' => 'parsed', 'direction' => 'inbound']);

            $eventA = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now(),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);
            $eventB = EpcisEvent::query()->create([
                'document_id' => $document->id,
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinute(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            ]);
            $this->eventIds = [(int) $eventA->id, (int) $eventB->id];

            DB::table('event_epcs')->insert([
                ['event_id' => $eventA->id, 'epc_id' => $epc->id, 'role' => 'epcList', 'quantity' => null, 'uom' => null],
                ['event_id' => $eventB->id, 'epc_id' => $epc->id, 'role' => 'epcList', 'quantity' => null, 'uom' => null],
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    'document_id' => $document->id,
                    'epc_id' => $epc->id,
                    'ingest_generation' => 1,
                ]);
            }

            EventBizTransaction::query()->create([
                'event_id' => $eventA->id,
                'type_uri' => 'urn:epcglobal:cbv:btt:desadv',
                'value' => 'BT-'.$suffix,
            ]);

            $result = app(SearchEpcisSchema::class)->handle('epcs', [
                ['field' => 'ilmd.lot_number', 'operator' => 'eq', 'value' => $lot],
                ['field' => 'event.event_type', 'operator' => 'eq', 'value' => 'ObjectEvent'],
            ]);

            $this->assertSame(1, $result['total']);
            $this->assertCount(1, $result['rows']);
            $this->assertSame((int) $epc->id, (int) $result['rows']->first()->id);
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
            'original_filename' => 'search-test.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/search-test-'.str()->uuid().'.xml',
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeEpc(array $attributes): Epc
    {
        $epc = Epc::query()->create(array_merge([
            'epc_type' => 'sgtin',
            'company_prefix' => '030116',
            'first_seen_at' => now(),
        ], $attributes));

        $this->epcIds[] = (int) $epc->id;

        return $epc;
    }

    private function makeIlmd(Epc $epc, string $gtin, string $lot): void
    {
        $attrs = [
            'epc_id' => $epc->id,
            'lot_number' => $lot,
        ];

        if (Schema::hasColumn('epc_ilmd', 'gtin14')) {
            $attrs['gtin14'] = $gtin;
        }

        EpcIlmd::query()->create($attrs);
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

        foreach ($this->eventIds as $id) {
            DB::table('event_epcs')->where('event_id', $id)->delete();
            EventBizTransaction::query()->where('event_id', $id)->delete();
            EpcisEvent::query()->whereKey($id)->delete();
        }
        $this->eventIds = [];

        foreach ($this->documentIds as $id) {
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('document_id', $id)->delete();
            }
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];

        foreach ($this->epcIds as $id) {
            EpcIlmd::query()->where('epc_id', $id)->delete();
            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->where('epc_id', $id)->delete();
            }
            DB::table('event_epcs')->where('epc_id', $id)->delete();
            Epc::query()->whereKey($id)->delete();
        }
        $this->epcIds = [];

        tenancy()->end();
    }
}
