<?php

namespace Tests\Unit\Services\Tracing;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EventBizTransaction;
use App\Models\Epcis\EventLocation;
use App\Models\Product;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Tracing\BuildAssetTrace;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BuildAssetTraceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $epcId = null;

    private ?int $childEpcId = null;

    private ?int $parentEpcId = null;

    private ?int $productId = null;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    #[Test]
    public function it_builds_a_full_trace_for_a_found_sgtin_with_events_lot_and_parties(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(10000000, 99999999);
            $itemRef = substr($suffix, 0, 6);
            $serial = 'SN-'.$suffix;
            $uri = "urn:epc:id:sgtin:030116.3{$itemRef}.{$serial}";

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now()->subHour(),
                'received_at' => now()->subHour(),
                'sender_gln' => '0301160000009',
                'receiver_gln' => '0096295000009',
                'ship_from_name' => 'Acme Wholesale',
                'ship_from_gln' => '0301160000009',
                'ship_from_site_name' => 'Acme DC 12',
                'ship_to_name' => 'Demo Pharmacy',
                'ship_to_gln' => '0096295000009',
                'ship_to_site_name' => 'Demo Pharmacy Main',
            ]);
            $this->documentId = (int) $document->getKey();

            $epc = Epc::fromUri($uri);
            $epc->save();
            $this->epcId = (int) $epc->getKey();
            $gtin14 = (string) $epc->gtin14;

            $ndc11 = '99'.$suffix.'0';

            $product = Product::query()->create([
                'gtin' => $gtin14,
                'name' => 'Amoxicillin 500mg',
                'dosage_form' => 'Capsule',
                'strength' => '500 mg',
                'ndc' => $ndc11,
                'ndc11' => $ndc11,
            ]);
            $this->productId = (int) $product->getKey();

            $epc->product_id = $product->getKey();
            $epc->save();

            EpcIlmd::query()->create([
                'epc_id' => $epc->getKey(),
                'lot_number' => 'LOT-A1',
                'expiry_date' => '2026-12-31',
                'manufacturing_date' => '2026-01-15',
            ]);

            $commissionEvent = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinutes(30),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'disposition' => 'urn:epcglobal:cbv:disp:active',
            ]);

            $shippingEvent = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinutes(10),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
                'read_point_gln' => '0301160000009',
                'biz_location_gln' => '0301160000009',
            ]);

            DB::table('event_epcs')->insert([
                ['event_id' => $commissionEvent->getKey(), 'epc_id' => $epc->getKey(), 'role' => 'epcList'],
                ['event_id' => $shippingEvent->getKey(), 'epc_id' => $epc->getKey(), 'role' => 'epcList'],
            ]);

            EventLocation::query()->create([
                'event_id' => $shippingEvent->getKey(),
                'location_type' => 'bizLocation',
                'gln' => '0301160000009',
                'name' => 'Acme DC 12',
                'latitude' => 41.85,
                'longitude' => -87.65,
            ]);

            EventBizTransaction::query()->create([
                'event_id' => $shippingEvent->getKey(),
                'type_uri' => 'urn:epcglobal:cbv:btt:po',
                'value' => 'urn:epcglobal:cbv:bt:0096295000009:PO-TEST-123',
            ]);

            EventBizTransaction::query()->create([
                'event_id' => $shippingEvent->getKey(),
                'type_uri' => 'urn:epcglobal:cbv:btt:desadv',
                'value' => 'urn:epcglobal:cbv:bt:0301160000009:ASN-TEST-456',
            ]);

            $childUri = "urn:epc:id:sgtin:030116.3{$itemRef}.{$serial}-CHILD";
            $childEpc = Epc::fromUri($childUri);
            $childEpc->save();
            $this->childEpcId = (int) $childEpc->getKey();

            AggregationLink::query()->create([
                'parent_epc_id' => $epc->getKey(),
                'child_epc_id' => $childEpc->getKey(),
                'established_by_event_id' => $shippingEvent->getKey(),
                'link_type' => 'aggregation',
                'valid_from' => now()->subMinutes(10),
            ]);

            $result = app(BuildAssetTrace::class)->handle("(01){$gtin14}(21){$serial}");

            $this->assertTrue($result['found']);
            $this->assertSame('Not in custody', $result['status']);
            $this->assertSame('warn', $result['status_tone']);
            $this->assertSame((int) $epc->getKey(), $result['epc']);

            $this->assertSame($gtin14.' · '.$serial, $result['primary_identifier']);
            $this->assertSame('01'.$gtin14.'21'.$serial.'1726123110LOT-A1', $result['gs1_barcode']);
            $this->assertSame($uri, $result['urn']);

            $this->assertSame('in_transit', $result['disposition']);
            $this->assertSame('urn:epcglobal:cbv:disp:in_transit', $result['disposition_uri']);
            $this->assertNotNull($result['disposition_at']);

            $this->assertSame('Case', $result['container_type']);
            $this->assertSame($serial, $result['serial_number']);
            $this->assertSame('Acme DC 12', $result['last_seen_at']);
            $this->assertSame(1, $result['children_count']);
            $this->assertNull($result['parent']);

            $childResult = app(BuildAssetTrace::class)->handle($childUri);
            $this->assertTrue($childResult['found']);
            $this->assertSame('In transit', $childResult['status']);
            $this->assertNotNull($childResult['parent']);
            $this->assertSame((int) $epc->getKey(), $childResult['parent']['epc_id']);
            $this->assertSame($gtin14.' · '.$serial, $childResult['parent']['primary_identifier']);
            $this->assertNotNull($childResult['parent']['url']);

            $this->assertSame('Amoxicillin 500mg', $result['product']['name']);
            $this->assertSame($ndc11, $result['product']['ndc11']);
            $this->assertSame('Capsule', $result['product']['dosage_form']);
            $this->assertSame('500 mg', $result['product']['strength']);
            $this->assertTrue($result['product']['linked']);

            $this->assertSame('LOT-A1', $result['lot']['lot_number']);
            $this->assertSame('2026-12-31', $result['lot']['expiry_date']);
            $this->assertSame('2026-01-15', $result['lot']['manufacturing_date']);

            $this->assertArrayHasKey('Seller', $result['parties']);
            $this->assertStringContainsString('Acme Wholesale', (string) $result['parties']['Seller']);
            $this->assertArrayHasKey('Ship-to', $result['parties']);
            $this->assertStringContainsString('Demo Pharmacy Main', (string) $result['parties']['Ship-to']);

            $this->assertCount(2, $result['timeline']);
            $this->assertSame('commissioning', $result['timeline'][0]['business_step']);
            $this->assertSame('shipping', $result['timeline'][1]['business_step']);
            $this->assertSame('in_transit', $result['timeline'][1]['disposition']);
            $this->assertSame('Acme DC 12', $result['timeline'][1]['site']);

            $this->assertCount(1, $result['map_points']);
            $this->assertSame('Acme DC 12', $result['map_points'][0]['label']);
            $this->assertEqualsWithDelta(41.85, $result['map_points'][0]['lat'], 0.0001);
            $this->assertEqualsWithDelta(-87.65, $result['map_points'][0]['lng'], 0.0001);

            $transactionNames = array_column($result['transactions'], 'name');
            $this->assertContains('Purchase Order', $transactionNames);
            $this->assertContains('(ASN) Despatch Advice', $transactionNames);

            $this->assertSame([
                'barcode' => '01'.$gtin14.'21'.$serial.'1726123110LOT-A1',
                'gtin' => $gtin14,
                'serial' => $serial,
            ], $result['verify_url_params']);

            // Table-backed query helpers used by Filament tabs.
            $service = app(BuildAssetTrace::class);
            $this->assertSame(2, $service->eventsQuery($epc)->count());
            $this->assertSame(1, $service->childrenQuery($epc)->count());
            $this->assertCount(2, $service->transactionsForEpc($epc));

            AggregationLink::query()
                ->where('parent_epc_id', $epc->getKey())
                ->where('child_epc_id', $childEpc->getKey())
                ->update(['valid_to' => now()]);

            $unpackedChild = app(BuildAssetTrace::class)->handle((string) $childEpc->ai_01_21);
            $this->assertNull($unpackedChild['parent']);
            $this->assertSame(0, $unpackedChild['children_count']);
            $this->assertSame(0, $service->childrenQuery($epc)->count());

            $this->assertTrue(BuildAssetTrace::isTrackable($shippingEvent));
            $this->assertFalse(BuildAssetTrace::isTrackable($commissionEvent));

            // Opening a quarantine hold flips status without touching identity fields.
            QuarantineHold::query()->create([
                'epc_id' => $epc->getKey(),
                'reason' => 'Test hold',
                'status' => 'open',
                'opened_at' => now(),
            ]);

            $quarantinedResult = app(BuildAssetTrace::class)->handle("(01){$gtin14}(21){$serial}");
            $this->assertSame('Quarantined', $quarantinedResult['status']);
            $this->assertSame('warn', $quarantinedResult['status_tone']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function events_query_excludes_never_validated_error_ingest_but_keeps_last_good(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TR'.$suffix;
            $epc = Epc::fromUri($uri);
            $epc->save();
            $this->epcId = (int) $epc->getKey();

            $errorDocument = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'error',
                'ingest_generation' => 1,
                'creation_date' => now()->subHour(),
                'received_at' => now()->subHour(),
            ]);
            $this->documentId = (int) $errorDocument->getKey();

            $errorEvent = EpcisEvent::query()->create([
                'document_id' => $errorDocument->getKey(),
                'ingest_generation' => 1,
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinutes(10),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            ]);
            DB::table('event_epcs')->insert([
                'event_id' => $errorEvent->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ]);

            $this->assertSame(0, app(BuildAssetTrace::class)->eventsQuery($epc)->count());

            $errorDocument->forceFill(['processed_at' => now()->subHour()])->save();
            $this->assertSame(1, app(BuildAssetTrace::class)->eventsQuery($epc)->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_implies_parent_sscc_shipping_disposition_on_child_display(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(10000000, 99999999);
            $itemRef = substr($suffix, 0, 6);
            $serial = 'SN-IMP-'.$suffix;
            $childUri = "urn:epc:id:sgtin:030116.3{$itemRef}.{$serial}";
            $ssccUri = 'urn:epc:id:sscc:030116.01'.$suffix.'0';

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now()->subHour(),
                'received_at' => now()->subHour(),
                'sender_gln' => '0301160000009',
                'receiver_gln' => '0096295000009',
                'ship_from_name' => 'Acme Wholesale',
                'ship_from_gln' => '0301160000009',
                'ship_from_site_name' => 'Acme DC 12',
                'ship_to_name' => 'Demo Pharmacy',
                'ship_to_gln' => '0096295000009',
                'ship_to_site_name' => 'Demo Pharmacy Main',
            ]);
            $this->documentId = (int) $document->getKey();

            $child = Epc::fromUri($childUri);
            $child->save();
            $this->epcId = (int) $child->getKey();
            $gtin14 = (string) $child->gtin14;

            $sscc = Epc::fromUri($ssccUri);
            $sscc->save();
            $this->parentEpcId = (int) $sscc->getKey();

            $commissionEvent = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinutes(40),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
                'disposition' => 'urn:epcglobal:cbv:disp:active',
            ]);

            $packingEvent = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'AggregationEvent',
                'event_time' => now()->subMinutes(30),
                'action' => 'ADD',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            ]);

            $shippingEvent = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinutes(10),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
                'read_point_gln' => '0301160000009',
                'biz_location_gln' => '0301160000009',
            ]);

            DB::table('event_epcs')->insert([
                ['event_id' => $commissionEvent->getKey(), 'epc_id' => $child->getKey(), 'role' => 'epcList'],
                ['event_id' => $packingEvent->getKey(), 'epc_id' => $child->getKey(), 'role' => 'childEPCs'],
                ['event_id' => $shippingEvent->getKey(), 'epc_id' => $sscc->getKey(), 'role' => 'epcList'],
            ]);

            EventLocation::query()->create([
                'event_id' => $shippingEvent->getKey(),
                'location_type' => 'bizLocation',
                'gln' => '0301160000009',
                'name' => 'Acme DC 12',
                'latitude' => 41.85,
                'longitude' => -87.65,
            ]);

            AggregationLink::query()->create([
                'parent_epc_id' => $sscc->getKey(),
                'child_epc_id' => $child->getKey(),
                'established_by_event_id' => $packingEvent->getKey(),
                'link_type' => 'aggregation',
                'valid_from' => now()->subMinutes(30),
            ]);

            $result = app(BuildAssetTrace::class)->handle("(01){$gtin14}(21){$serial}");

            $this->assertTrue($result['found']);
            $this->assertNotNull($result['parent']);
            $this->assertSame((int) $sscc->getKey(), $result['parent']['epc_id']);

            // Status HUD: last direct event only (packing), not implied parent shipping.
            $this->assertSame('in_progress', $result['disposition']);
            $this->assertSame('urn:epcglobal:cbv:disp:in_progress', $result['disposition_uri']);
            $this->assertSame('In transit', $result['status']);
            $this->assertSame('warn', $result['status_tone']);

            // Timeline still merges inferred ancestor shipping for display history.
            $shippingSteps = array_values(array_filter(
                $result['timeline'],
                fn (array $step): bool => ($step['business_step'] ?? null) === 'shipping',
            ));
            $this->assertCount(1, $shippingSteps);
            $this->assertTrue($shippingSteps[0]['inferred']);
            $this->assertSame('in_transit', $shippingSteps[0]['disposition']);
            $this->assertNotEmpty($shippingSteps[0]['inferred_from']);

            $service = app(BuildAssetTrace::class);
            $this->assertSame(2, $service->eventsQuery($child)->count());
            $this->assertFalse(
                $service->eventsQuery($child)->whereKey($shippingEvent->getKey())->exists(),
            );

            AggregationLink::query()
                ->where('parent_epc_id', $sscc->getKey())
                ->where('child_epc_id', $child->getKey())
                ->update(['valid_to' => now()]);

            $unpacked = app(BuildAssetTrace::class)->handle("(01){$gtin14}(21){$serial}");
            $this->assertNull($unpacked['parent']);
            $this->assertSame('in_progress', $unpacked['disposition']);
            $this->assertSame('urn:epcglobal:cbv:disp:in_progress', $unpacked['disposition_uri']);

            $unpackedShipping = array_values(array_filter(
                $unpacked['timeline'],
                fn (array $step): bool => ($step['business_step'] ?? null) === 'shipping',
            ));
            $this->assertCount(1, $unpackedShipping);
            $this->assertTrue($unpackedShipping[0]['inferred']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_caps_initial_timeline_events_for_hot_path_payloads(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.tracing.initial_direct_events_limit' => 5,
                'tracepharma.tracing.initial_timeline_events_limit' => 3,
            ]);

            $suffix = (string) random_int(10000000, 99999999);
            $itemRef = substr($suffix, 0, 6);
            $serial = 'CAP-'.$suffix;
            $uri = "urn:epc:id:sgtin:030116.3{$itemRef}.{$serial}";

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now()->subHour(),
                'received_at' => now()->subHour(),
            ]);
            $this->documentId = (int) $document->getKey();

            $epc = Epc::fromUri($uri);
            $epc->save();
            $this->epcId = (int) $epc->getKey();

            for ($i = 0; $i < 8; $i++) {
                $event = EpcisEvent::query()->create([
                    'document_id' => $document->getKey(),
                    'event_type' => 'ObjectEvent',
                    'event_time' => now()->subMinutes(60 - $i),
                    'action' => 'OBSERVE',
                    'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                    'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
                ]);

                DB::table('event_epcs')->insert([
                    'event_id' => $event->getKey(),
                    'epc_id' => $epc->getKey(),
                    'role' => 'epcList',
                ]);
            }

            $result = app(BuildAssetTrace::class)->handle("(01){$epc->gtin14}(21){$serial}");

            $this->assertTrue($result['found']);
            $this->assertCount(3, $result['timeline']);
            $this->assertSame(8, app(BuildAssetTrace::class)->eventsQuery($epc)->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_returns_a_not_found_result_for_an_unresolvable_scan(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $result = app(BuildAssetTrace::class)->handle('UNKNOWN-LABEL-XYZ');

            $this->assertFalse($result['found']);
            $this->assertSame('Not found', $result['status']);
            $this->assertSame('error', $result['status_tone']);
            $this->assertNull($result['epc']);
            $this->assertSame(0, $result['children_count']);
            $this->assertSame([], $result['timeline']);
            $this->assertSame([], $result['map_points']);
            $this->assertFalse($result['product']['linked']);
            $this->assertNull($result['verify_url_params']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_builds_a_numbered_journey_from_manufacturer_through_sites(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(10000000, 99999999);
            $itemRef = substr($suffix, 0, 6);
            $serial = 'SN-MAP-'.$suffix;
            $uri = "urn:epc:id:sgtin:030116.3{$itemRef}.{$serial}";

            $manufacturer = TradingPartner::factory()->create([
                'name' => 'Xttrium Laboratories',
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Manufacturer,
                'latitude' => 41.85,
                'longitude' => -87.65,
            ]);
            $this->partnerIds[] = (int) $manufacturer->getKey();

            $shipFrom = Site::factory()->owned()->create([
                'name' => 'Acme DC 12',
                'gln' => fake()->unique()->numerify('#############'),
                'latitude' => 41.90,
                'longitude' => -87.70,
            ]);
            $this->siteIds[] = (int) $shipFrom->getKey();

            $current = Site::factory()->owned()->create([
                'name' => 'LA Smile Main',
                'gln' => fake()->unique()->numerify('#############'),
                'latitude' => 34.0522,
                'longitude' => -118.2437,
            ]);
            $this->siteIds[] = (int) $current->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'creation_date' => now()->subHour(),
                'received_at' => now()->subHour(),
                'sender_gln' => $manufacturer->gln,
                'receiver_gln' => $current->gln,
                'ship_from_name' => 'Acme Wholesale',
                'ship_from_gln' => $shipFrom->gln,
                'ship_from_site_name' => 'Acme DC 12',
                'ship_to_name' => 'LA Smile',
                'ship_to_gln' => $current->gln,
                'ship_to_site_name' => 'LA Smile Main',
            ]);
            $this->documentId = (int) $document->getKey();

            $epc = Epc::fromUri($uri);
            $epc->save();
            $this->epcId = (int) $epc->getKey();

            $product = Product::query()->create([
                'gtin' => (string) $epc->gtin14,
                'name' => 'Chlorhexidine 2%',
                'trading_partner_id' => $manufacturer->getKey(),
            ]);
            $this->productId = (int) $product->getKey();
            $epc->product_id = $product->getKey();
            $epc->save();

            $shippingEvent = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinutes(20),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
                'read_point_gln' => $shipFrom->gln,
                'biz_location_gln' => $shipFrom->gln,
            ]);

            $receivingEvent = EpcisEvent::query()->create([
                'document_id' => $document->getKey(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->subMinutes(5),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
                'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
                'read_point_gln' => $current->gln,
                'biz_location_gln' => $current->gln,
            ]);

            DB::table('event_epcs')->insert([
                ['event_id' => $shippingEvent->getKey(), 'epc_id' => $epc->getKey(), 'role' => 'epcList'],
                ['event_id' => $receivingEvent->getKey(), 'epc_id' => $epc->getKey(), 'role' => 'epcList'],
            ]);

            EventLocation::query()->create([
                'event_id' => $shippingEvent->getKey(),
                'location_type' => 'bizLocation',
                'gln' => $shipFrom->gln,
                'name' => 'Acme DC 12 dock',
                'latitude' => null,
                'longitude' => null,
            ]);

            EventLocation::query()->create([
                'event_id' => $receivingEvent->getKey(),
                'location_type' => 'bizLocation',
                'gln' => $current->gln,
                'name' => 'LA Smile receiving',
                'latitude' => null,
                'longitude' => null,
            ]);

            $result = app(BuildAssetTrace::class)->handle("(01){$epc->gtin14}(21){$serial}");

            $this->assertTrue($result['found']);
            $this->assertCount(3, $result['map_points']);
            $this->assertSame([1, 2, 3], array_column($result['map_points'], 'seq'));
            $this->assertSame('Xttrium Laboratories', $result['map_points'][0]['label']);
            $this->assertEqualsWithDelta(41.85, $result['map_points'][0]['lat'], 0.0001);
            $this->assertSame('Acme DC 12 dock', $result['map_points'][1]['label']);
            $this->assertEqualsWithDelta(41.90, $result['map_points'][1]['lat'], 0.0001);
            $this->assertSame('LA Smile receiving', $result['map_points'][2]['label']);
            $this->assertEqualsWithDelta(34.0522, $result['map_points'][2]['lat'], 0.0001);
        } finally {
            $this->cleanup();
        }
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

        if ($this->epcId !== null) {
            QuarantineHold::query()->where('epc_id', $this->epcId)->delete();
        }

        $epcIds = array_filter([$this->epcId, $this->childEpcId, $this->parentEpcId]);
        if ($epcIds !== []) {
            DB::table('aggregation_links')->whereIn('parent_epc_id', $epcIds)->orWhereIn('child_epc_id', $epcIds)->delete();
            DB::table('event_epcs')->whereIn('epc_id', $epcIds)->delete();
            EpcIlmd::query()->whereIn('epc_id', $epcIds)->delete();
            Epc::query()->whereIn('id', $epcIds)->delete();
        }

        if ($this->documentId !== null) {
            $eventIds = EpcisEvent::query()->where('document_id', $this->documentId)->pluck('id');
            if ($eventIds->isNotEmpty()) {
                DB::table('event_locations')->whereIn('event_id', $eventIds)->delete();
                DB::table('event_biz_transactions')->whereIn('event_id', $eventIds)->delete();
            }
            EpcisEvent::query()->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
        }

        if ($this->productId !== null) {
            Product::query()->whereKey($this->productId)->delete();
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            $this->partnerIds = [];
        }

        $this->epcId = null;
        $this->childEpcId = null;
        $this->parentEpcId = null;
        $this->documentId = null;
        $this->productId = null;

        tenancy()->end();
    }
}
