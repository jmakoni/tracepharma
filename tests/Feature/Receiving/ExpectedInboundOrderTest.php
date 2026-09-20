<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\ConfirmRemainingExpectedReceivingLines;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Receiving\PropagateScanFirstConfirmsToAsnSession;
use App\Actions\Receiving\SyncInboundExpectedLinesFromDocument;
use App\Enums\EpcisAuthoredKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Copy\OperatorNouns;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ExpectedInboundOrderHeader;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Receiving\ReceivingSessionCompleteCopy;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ExpectedInboundOrderTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEFAULT_SSCC = 'urn:epc:id:sscc:030116.01001227052';

    private const DEFAULT_SGTIN = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<string> */
    private array $epcUris = [];

    private ?bool $priorRequireTi = null;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?bool $priorParallel = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function ingest_shipping_and_agg_creates_shipment_with_parent_child_expected_lines(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('inbound_expected_lines'));

            $ingested = $this->ingestUniqueShippingRefs();
            $document = $ingested['document'];
            $shipmentId = (int) $document->inbound_shipment_id;

            $this->assertGreaterThan(0, $shipmentId);

            $shipment = InboundShipment::query()->findOrFail($shipmentId);
            $shipment->refreshRollups();

            $parentLine = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('line_role', 'parent')
                ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ingested['sscc_uri']))
                ->first();
            $childLine = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('line_role', 'child')
                ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ingested['sgtin_uri']))
                ->first();

            $this->assertNotNull($parentLine);
            $this->assertSame('expected', $parentLine->status);
            $this->assertNotNull($childLine);
            $this->assertSame('expected', $childLine->status);
            $this->assertSame((int) $parentLine->epc_id, (int) $childLine->parent_epc_id);

            $leafCount = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('line_role', 'child')
                ->whereIn('status', ['expected', 'confirmed'])
                ->count();

            $this->assertSame($leafCount, (int) $shipment->fresh()->expected_each_count);
            $this->assertSame(1, (int) $shipment->fresh()->expected_parent_count);
            $this->assertSame('expected', $shipment->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function same_asn_reingest_does_not_duplicate_shipment_or_expected_lines(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(100000, 999999);
            $asn = 'ASN-DEDUP-'.$suffix;
            $po = 'PO-DEDUP-'.$suffix;
            $sscc = 'urn:epc:id:sscc:030116.01011'.$suffix;
            $sgtin = 'urn:epc:id:sgtin:030116.0200116.1'.$suffix;

            $docA = $this->ingestShippingRefsFixture($sscc, $sgtin, $asn, $po);
            $shipmentId = (int) $docA->inbound_shipment_id;
            $this->assertGreaterThan(0, $shipmentId);

            $lineCountBefore = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->count();
            $this->assertGreaterThan(0, $lineCountBefore);

            // Same delivery serials + ASN again (new document uuid).
            $docB = $this->ingestShippingRefsFixture($sscc, $sgtin, $asn, $po);

            $this->assertSame($shipmentId, (int) $docB->inbound_shipment_id);
            $this->assertSame(
                1,
                InboundShipment::query()->where('asn_number', $asn)->count(),
            );
            $this->assertSame(
                $lineCountBefore,
                InboundExpectedLine::query()->where('inbound_shipment_id', $shipmentId)->count(),
            );

            // Sync is also idempotent when re-run on the original document.
            app(SyncInboundExpectedLinesFromDocument::class)->handle($docA->fresh());
            $this->assertSame(
                $lineCountBefore,
                InboundExpectedLine::query()->where('inbound_shipment_id', $shipmentId)->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function addendum_same_asn_adds_new_expected_lines_on_same_shipment(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-ADD-'.$suffixA;
            $po = 'PO-ADD-'.$suffixA;

            $docA = $this->ingestShippingRefsFixture(
                'urn:epc:id:sscc:030116.01012'.$suffixA,
                'urn:epc:id:sgtin:030116.0200116.2'.$suffixA,
                $asn,
                $po,
            );
            $shipmentId = (int) $docA->inbound_shipment_id;
            $linesAfterFirst = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->count();

            $docB = $this->ingestShippingRefsFixture(
                'urn:epc:id:sscc:030116.01012'.$suffixB,
                'urn:epc:id:sgtin:030116.0200116.2'.$suffixB,
                $asn,
                $po,
            );

            $this->assertSame($shipmentId, (int) $docB->inbound_shipment_id);

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $linesAfterAddendum = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->count();

            $this->assertGreaterThan($linesAfterFirst, $linesAfterAddendum);
            $this->assertSame(2, (int) $shipment->document_count);
            $this->assertSame(2, (int) $shipment->expected_parent_count);
            $this->assertSame(2, (int) $shipment->expected_each_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function multi_day_partial_receive_opens_new_session_then_completes_shipment(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-DAY-'.$suffixA;
            $po = 'PO-DAY-'.$suffixA;
            $ssccA = 'urn:epc:id:sscc:030116.01013'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01013'.$suffixB;
            // Same GTIN + lot (fixture lot 606412T), different serials.
            $sgtinA = 'urn:epc:id:sgtin:030116.0200116.3'.$suffixA;
            $sgtinB = 'urn:epc:id:sgtin:030116.0200116.3'.$suffixB;

            $docA = $this->ingestShippingRefsFixture($ssccA, $sgtinA, $asn, $po);
            $docB = $this->ingestShippingRefsFixture($ssccB, $sgtinB, $asn, $po);
            $shipmentId = (int) $docA->inbound_shipment_id;
            $this->assertSame($shipmentId, (int) $docB->inbound_shipment_id);

            $day1 = $this->openAsnSession($docA);
            $this->assertSame(2, (int) $day1->fresh()->expected_parent_count);

            $policy = ReceivingPolicy::forTenant($tenant);
            $confirmA = app(ConfirmReceivingScan::class)->handle(
                $day1,
                $ssccA,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirmA['ok'], $confirmA['message'] ?? 'day-1 parent confirm failed');

            $day1 = app(CompleteReceivingSession::class)->handle($day1->fresh(), shortClose: true);
            $this->assertSame('completed', $day1->fresh()->status);

            $partialCopy = ReceivingSessionCompleteCopy::for($day1->fresh());
            $this->assertSame('Session complete', $partialCopy['title']);
            $this->assertFalse($partialCopy['document_complete']);
            $this->assertStringNotContainsString('All expected', $partialCopy['body']);
            $this->assertStringNotContainsString('Receiving complete', $partialCopy['title']);
            $this->assertStringContainsString('still expected', $partialCopy['body']);

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $this->assertTrue($shipment->hasRemainingExpected());
            $this->assertSame('open', $shipment->status);
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccA))
                    ->value('status'),
            );
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->value('status'),
            );

            $day2 = $this->openAsnSession($docA);
            $this->assertNotSame((int) $day1->getKey(), (int) $day2->getKey());
            $this->assertSame('open', $day2->status);
            $this->assertSame($shipmentId, (int) $day2->inbound_shipment_id);
            $this->assertSame(1, (int) $day2->expected_parent_count);
            $this->assertTrue(
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $day2->getKey())
                    ->where('line_role', 'parent')
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->exists(),
            );

            $confirmB = app(ConfirmReceivingScan::class)->handle(
                $day2,
                $ssccB,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirmB['ok'], $confirmB['message'] ?? 'day-2 parent confirm failed');

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $this->assertFalse($shipment->hasRemainingExpected());
            $this->assertSame('complete', $shipment->status);

            $day2 = $day2->fresh();
            app(CompleteReceivingSession::class)->handle($day2, shortClose: true);
            $fullCopy = ReceivingSessionCompleteCopy::for($day2->fresh());
            $this->assertSame('ASN complete', $fullCopy['title']);
            $this->assertTrue($fullCopy['document_complete']);
            $this->assertStringContainsString('All expected', $fullCopy['body']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function rescan_day1_sscc_on_day2_returns_already_received(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-RES-'.$suffixA;
            $po = 'PO-RES-'.$suffixA;
            $ssccA = 'urn:epc:id:sscc:030116.01014'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01014'.$suffixB;

            $docA = $this->ingestShippingRefsFixture(
                $ssccA,
                'urn:epc:id:sgtin:030116.0200116.4'.$suffixA,
                $asn,
                $po,
            );
            $docB = $this->ingestShippingRefsFixture(
                $ssccB,
                'urn:epc:id:sgtin:030116.0200116.4'.$suffixB,
                $asn,
                $po,
            );

            $policy = ReceivingPolicy::forTenant($tenant);
            $day1 = $this->openAsnSession($docA);
            $this->assertTrue(
                app(ConfirmReceivingScan::class)->handle(
                    $day1,
                    $ssccA,
                    null,
                    $policy->defaultAutoConfirmChildren(),
                )['ok'],
            );
            app(CompleteReceivingSession::class)->handle($day1->fresh(), shortClose: true);

            $day2 = $this->openAsnSession($docB);
            $rescan = app(ConfirmReceivingScan::class)->handle(
                $day2,
                $ssccA,
                null,
                $policy->defaultAutoConfirmChildren(),
            );

            $this->assertTrue($rescan['ok']);
            $this->assertSame('already_received', $rescan['effect']);
            $this->assertStringContainsString('Already received', (string) $rescan['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function serial_on_other_asn_same_po_gtin_lot_is_unexpected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $po = 'PO-XASN-'.$suffixA;
            $ssccA = 'urn:epc:id:sscc:030116.01015'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01015'.$suffixB;
            // Same GTIN company/item, different serials; shared PO; distinct ASNs.
            $sgtinA = 'urn:epc:id:sgtin:030116.0200116.5'.$suffixA;
            $sgtinB = 'urn:epc:id:sgtin:030116.0200116.5'.$suffixB;

            $docA = $this->ingestShippingRefsFixture($ssccA, $sgtinA, 'ASN-XASN-A-'.$suffixA, $po);
            $docB = $this->ingestShippingRefsFixture($ssccB, $sgtinB, 'ASN-XASN-B-'.$suffixB, $po);
            $this->assertNotSame(
                (int) $docA->inbound_shipment_id,
                (int) $docB->inbound_shipment_id,
            );

            $session = $this->openAsnSession($docA);
            $policy = ReceivingPolicy::forTenant($tenant);

            $foreign = app(ConfirmReceivingScan::class)->handle(
                $session,
                $ssccB,
                null,
                $policy->defaultAutoConfirmChildren(),
            );

            $this->assertFalse($foreign['ok']);
            $this->assertSame('unexpected', $foreign['effect']);

            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $docA->inbound_shipment_id)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccA))
                    ->value('status'),
            );
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $docB->inbound_shipment_id)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->value('status'),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parent_confirm_with_auto_confirm_children_confirms_child_expected_lines(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $ingested = $this->ingestUniqueShippingRefs();
            $shipmentId = (int) $ingested['document']->inbound_shipment_id;
            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $eachBefore = (int) $shipment->confirmed_each_count;
            $leafCount = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('line_role', 'child')
                ->count();
            $this->assertGreaterThan(0, $leafCount);

            $session = $this->openAsnSession($ingested['document']);
            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertTrue($policy->defaultAutoConfirmChildren());

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                $ingested['sscc_uri'],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('line_role', 'parent')
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ingested['sscc_uri']))
                    ->value('status'),
            );
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('line_role', 'child')
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ingested['sgtin_uri']))
                    ->value('status'),
            );

            $shipment = $shipment->fresh()->refreshRollups();
            $this->assertSame($eachBefore + $leafCount, (int) $shipment->confirmed_each_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_then_open_asn_propagate_confirms_matching_expected_lines(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $suffixExtra = (string) random_int(100000, 999999);
            $asn = 'ASN-SF-'.$suffixA;
            $po = 'PO-SF-'.$suffixA;
            $ssccMatchA = 'urn:epc:id:sscc:030116.01016'.$suffixA;
            $ssccMatchB = 'urn:epc:id:sscc:030116.01016'.$suffixB;
            $ssccLeftover = 'urn:epc:id:sscc:030116.01016'.$suffixExtra;

            // Focused case 8: scan-first confirms for two parents that will be on the ASN;
            // file also includes one leftover expected parent that was never scanned.
            $docA = $this->ingestShippingRefsFixture(
                $ssccMatchA,
                'urn:epc:id:sgtin:030116.0200116.6'.$suffixA,
                $asn,
                $po,
            );
            $docB = $this->ingestShippingRefsFixture(
                $ssccMatchB,
                'urn:epc:id:sgtin:030116.0200116.6'.$suffixB,
                $asn,
                $po,
            );
            $docLeftover = $this->ingestShippingRefsFixture(
                $ssccLeftover,
                'urn:epc:id:sgtin:030116.0200116.6'.$suffixExtra,
                $asn,
                $po,
            );
            $shipmentId = (int) $docA->inbound_shipment_id;
            $this->assertSame($shipmentId, (int) $docB->inbound_shipment_id);
            $this->assertSame($shipmentId, (int) $docLeftover->inbound_shipment_id);

            $siteId = $this->resolveEligibleReceiveSiteId();
            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionIds[] = (int) $scanFirst->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            foreach ([$ssccMatchA, $ssccMatchB] as $uri) {
                $result = app(ConfirmReceivingScan::class)->handle(
                    $scanFirst->fresh(),
                    $uri,
                    null,
                    $policy->defaultAutoConfirmChildren(),
                );
                $this->assertTrue($result['ok'], $result['message'] ?? "scan-first confirm failed for {$uri}");
            }

            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccMatchA))
                    ->value('status'),
            );
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccMatchB))
                    ->value('status'),
            );
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccLeftover))
                    ->value('status'),
            );
            $docA->unsetRelation('inboundShipment');
            $this->assertSame(OperatorNouns::FLOOR_PARTIALLY_RECEIVED, $docA->fresh()->load('inboundShipment')->floorReceiveStatusLabel());

            $asnSession = $this->openAsnSession($docA);
            app(PropagateScanFirstConfirmsToAsnSession::class)->handle($asnSession->fresh());

            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccMatchA))
                    ->value('status'),
            );
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccMatchB))
                    ->value('status'),
            );
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccLeftover))
                    ->value('status'),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_confirm_stamps_unique_open_asn_expected_lines_and_floor_badge(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-SF-BADGE-'.$suffixA;
            $po = 'PO-SF-BADGE-'.$suffixA;
            $ssccA = 'urn:epc:id:sscc:030116.01051'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01051'.$suffixB;

            $docA = $this->ingestShippingRefsFixture(
                $ssccA,
                'urn:epc:id:sgtin:030116.0200116.1'.$suffixA,
                $asn,
                $po,
            );
            $docB = $this->ingestShippingRefsFixture(
                $ssccB,
                'urn:epc:id:sgtin:030116.0200116.1'.$suffixB,
                $asn,
                $po,
            );
            $shipmentId = (int) $docA->inbound_shipment_id;
            $this->assertSame($shipmentId, (int) $docB->inbound_shipment_id);

            $siteId = $this->resolveEligibleReceiveSiteId();
            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionIds[] = (int) $scanFirst->getKey();
            $this->assertNull($scanFirst->inbound_shipment_id);

            $policy = ReceivingPolicy::forTenant($tenant);
            $first = app(ConfirmReceivingScan::class)->handle(
                $scanFirst->fresh(),
                $ssccA,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($first['ok'], $first['message'] ?? 'scan-first confirm failed for first pallet');

            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccA))
                    ->value('status'),
            );
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->value('status'),
            );

            $docA->unsetRelation('inboundShipment');
            $this->assertSame(
                OperatorNouns::FLOOR_PARTIALLY_RECEIVED,
                $docA->fresh()->load(['inboundShipment', 'receivingSession'])->floorReceiveStatusLabel(),
            );

            $second = app(ConfirmReceivingScan::class)->handle(
                $scanFirst->fresh(),
                $ssccB,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($second['ok'], $second['message'] ?? 'scan-first confirm failed for second pallet');

            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->value('status'),
            );
            $this->assertFalse(InboundShipment::query()->find($shipmentId)?->hasRemainingExpected());

            $docA->unsetRelation('inboundShipment');
            $this->assertSame(
                OperatorNouns::FLOOR_RECEIVED,
                $docA->fresh()->load(['inboundShipment', 'receivingSession'])->floorReceiveStatusLabel(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_confirm_does_not_stamp_when_epc_is_expected_on_two_open_asns(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $suffix = (string) random_int(100000, 999999);
            $sharedSscc = 'urn:epc:id:sscc:030116.01052'.$suffix;
            $docA = $this->ingestShippingRefsFixture(
                $sharedSscc,
                'urn:epc:id:sgtin:030116.0200116.2'.$suffix.'1',
                'ASN-AMB-A-'.$suffix,
                'PO-AMB-A-'.$suffix,
            );
            $docB = $this->ingestShippingRefsFixture(
                $sharedSscc,
                'urn:epc:id:sgtin:030116.0200116.2'.$suffix.'2',
                'ASN-AMB-B-'.$suffix,
                'PO-AMB-B-'.$suffix,
            );
            $this->assertNotSame((int) $docA->inbound_shipment_id, (int) $docB->inbound_shipment_id);

            $siteId = $this->resolveEligibleReceiveSiteId();
            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionIds[] = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $scanFirst->fresh(),
                $sharedSscc,
                null,
                false,
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'scan-first confirm failed');

            foreach ([(int) $docA->inbound_shipment_id, (int) $docB->inbound_shipment_id] as $shipmentId) {
                $this->assertSame(
                    'expected',
                    InboundExpectedLine::query()
                        ->where('inbound_shipment_id', $shipmentId)
                        ->whereHas('epc', fn ($q) => $q->where('epc_uri', $sharedSscc))
                        ->value('status'),
                );
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function custody_of_asn_sscc_stamps_expected_line_and_updates_floor_badge(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-CUSTODY-'.$suffixA;
            $po = 'PO-CUSTODY-'.$suffixA;
            $ssccA = 'urn:epc:id:sscc:030116.01053'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01053'.$suffixB;

            $docA = $this->ingestShippingRefsFixture(
                $ssccA,
                'urn:epc:id:sgtin:030116.0200116.3'.$suffixA,
                $asn,
                $po,
            );
            $docB = $this->ingestShippingRefsFixture(
                $ssccB,
                'urn:epc:id:sgtin:030116.0200116.3'.$suffixB,
                $asn,
                $po,
            );
            $shipmentId = (int) $docA->inbound_shipment_id;
            $this->assertSame($shipmentId, (int) $docB->inbound_shipment_id);

            $epcA = Epc::query()->where('epc_uri', $ssccA)->firstOrFail();
            $epcB = Epc::query()->where('epc_uri', $ssccB)->firstOrFail();
            $siteId = $this->resolveEligibleReceiveSiteId()
                ?? (int) array_key_first(EligibleReceiveSites::organizationOptions());
            $site = Site::query()->find($siteId);
            $this->assertNotNull($site);
            $this->assertNotEmpty($site->gln);

            $this->authorTenantReceiptAtSite($site, $epcA);

            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('epc_id', $epcA->getKey())
                    ->value('status'),
            );

            $docA->unsetRelation('inboundShipment');
            $this->assertSame(
                OperatorNouns::FLOOR_PARTIALLY_RECEIVED,
                $docA->fresh()->load(['inboundShipment', 'receivingSession'])->floorReceiveStatusLabel(),
            );
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('epc_id', $epcA->getKey())
                    ->value('status'),
            );
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('epc_id', $epcB->getKey())
                    ->value('status'),
            );

            $this->authorTenantReceiptAtSite($site, $epcB);

            $docA->unsetRelation('inboundShipment');
            $this->assertSame(
                OperatorNouns::FLOOR_RECEIVED,
                $docA->fresh()->load(['inboundShipment', 'receivingSession'])->floorReceiveStatusLabel(),
            );
            $this->assertFalse(InboundShipment::query()->find($shipmentId)?->hasRemainingExpected());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function correction_same_asn_subset_cancels_unconfirmed_expected_and_raises_asn_shipment_corrected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            ExceptionTypeSeeder::ensure('ASN_SHIPMENT_CORRECTED');

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-CORR-'.$suffixA;
            $po = 'PO-CORR-'.$suffixA;
            $ssccA = 'urn:epc:id:sscc:030116.01018'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01018'.$suffixB;
            $sgtinA = 'urn:epc:id:sgtin:030116.0200116.8'.$suffixA;
            $sgtinB = 'urn:epc:id:sgtin:030116.0200116.8'.$suffixB;

            $docA = $this->ingestShippingRefsFixture($ssccA, $sgtinA, $asn, $po);
            $docB = $this->ingestShippingRefsFixture($ssccB, $sgtinB, $asn, $po);
            $shipmentId = (int) $docA->inbound_shipment_id;
            $this->assertSame($shipmentId, (int) $docB->inbound_shipment_id);

            $policy = ReceivingPolicy::forTenant($tenant);
            $session = $this->openAsnSession($docA);
            $this->assertTrue(
                app(ConfirmReceivingScan::class)->handle(
                    $session,
                    $ssccA,
                    null,
                    $policy->defaultAutoConfirmChildren(),
                )['ok'],
            );

            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccA))
                    ->value('status'),
            );
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->value('status'),
            );

            // Same ASN, subset of prior serials (A only) → correction prune of unconfirmed B.
            $docCorrection = $this->ingestShippingRefsFixture($ssccA, $sgtinA, $asn, $po);

            $this->assertSame($shipmentId, (int) $docCorrection->inbound_shipment_id);
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccA))
                    ->value('status'),
            );
            $this->assertSame(
                'cancelled',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->value('status'),
            );
            $this->assertSame(
                'cancelled',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $sgtinB))
                    ->value('status'),
            );

            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $docCorrection->getKey())
                    ->where('exception_type', 'ASN_SHIPMENT_CORRECTED')
                    ->where('status', 'open')
                    ->exists(),
            );

            $this->assertFalse(
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('status', 'expected')
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->exists(),
            );

            $session = $session->fresh();
            $this->assertSame(1, (int) $session->expected_parent_count);
            $this->assertSame(1, (int) $session->confirmed_parent_count);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * 10-of-30 split-receive story at N=5: confirm 2 on session 1, leave 3;
     * session 2 rejects already-received and accepts a remaining parent.
     */
    #[Test]
    public function test_second_session_rejects_already_received_parent_and_accepts_remaining(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $base = (string) random_int(100000, 999999);
            $asn = 'ASN-SPLIT-'.$base;
            $po = 'PO-SPLIT-'.$base;

            $ssccs = [];
            $docs = [];
            for ($i = 0; $i < 5; $i++) {
                $suffix = (string) random_int(100000, 999999);
                $sscc = 'urn:epc:id:sscc:030116.01020'.$suffix;
                $sgtin = 'urn:epc:id:sgtin:030116.0200116.2'.$suffix;
                $ssccs[] = $sscc;
                $docs[] = $this->ingestShippingRefsFixture($sscc, $sgtin, $asn, $po);
            }

            $shipmentId = (int) $docs[0]->inbound_shipment_id;
            foreach ($docs as $doc) {
                $this->assertSame($shipmentId, (int) $doc->inbound_shipment_id);
            }

            $policy = ReceivingPolicy::forTenant($tenant);
            $session1 = $this->openAsnSession($docs[0]);
            $this->assertSame(5, (int) $session1->fresh()->expected_parent_count);

            foreach ([$ssccs[0], $ssccs[1]] as $sscc) {
                $confirm = app(ConfirmReceivingScan::class)->handle(
                    $session1->fresh(),
                    $sscc,
                    null,
                    $policy->defaultAutoConfirmChildren(),
                );
                $this->assertTrue($confirm['ok'], $confirm['message'] ?? "session-1 confirm failed for {$sscc}");
            }

            $session1 = app(CompleteReceivingSession::class)->handle($session1->fresh(), shortClose: true);
            $this->assertSame('completed', $session1->fresh()->status);

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $this->assertTrue($shipment->hasRemainingExpected());
            $this->assertSame('open', $shipment->status);
            $this->assertNotSame('complete', $shipment->status);

            foreach ([$ssccs[0], $ssccs[1]] as $sscc) {
                $this->assertSame(
                    'confirmed',
                    InboundExpectedLine::query()
                        ->where('inbound_shipment_id', $shipmentId)
                        ->whereHas('epc', fn ($q) => $q->where('epc_uri', $sscc))
                        ->value('status'),
                );
            }
            foreach ([$ssccs[2], $ssccs[3], $ssccs[4]] as $sscc) {
                $this->assertSame(
                    'expected',
                    InboundExpectedLine::query()
                        ->where('inbound_shipment_id', $shipmentId)
                        ->whereHas('epc', fn ($q) => $q->where('epc_uri', $sscc))
                        ->value('status'),
                );
            }

            $session2 = $this->openAsnSession($docs[0]);
            $this->assertNotSame((int) $session1->getKey(), (int) $session2->getKey());
            $this->assertSame($shipmentId, (int) $session2->inbound_shipment_id);
            $this->assertSame(3, (int) $session2->expected_parent_count);

            $rescan = app(ConfirmReceivingScan::class)->handle(
                $session2,
                $ssccs[0],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($rescan['ok']);
            $this->assertSame('already_received', $rescan['effect']);
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccs[0]))
                    ->value('status'),
            );

            $confirmRemaining = app(ConfirmReceivingScan::class)->handle(
                $session2->fresh(),
                $ssccs[2],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirmRemaining['ok'], $confirmRemaining['message'] ?? 'session-2 remaining confirm failed');
            $this->assertNotSame('already_received', $confirmRemaining['effect'] ?? null);
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccs[2]))
                    ->value('status'),
            );

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $this->assertTrue($shipment->hasRemainingExpected());
            $this->assertSame('open', $shipment->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function test_addendum_file_unions_new_parents_without_pruning_existing_expected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $base = (string) random_int(100000, 999999);
            $asn = 'ASN-ADDUN-'.$base;
            $po = 'PO-ADDUN-'.$base;

            $labels = ['A', 'B', 'C', 'D', 'E'];
            $ssccs = [];
            $docs = [];
            foreach ($labels as $i => $label) {
                $suffix = (string) random_int(100000, 999999);
                $sscc = 'urn:epc:id:sscc:030116.01021'.$suffix;
                $sgtin = 'urn:epc:id:sgtin:030116.0200116.3'.$suffix;
                $ssccs[$label] = $sscc;
                $docs[$label] = $this->ingestShippingRefsFixture($sscc, $sgtin, $asn, $po);
            }

            $shipmentId = (int) $docs['A']->inbound_shipment_id;
            foreach ($docs as $doc) {
                $this->assertSame($shipmentId, (int) $doc->inbound_shipment_id);
            }

            foreach (['A', 'B', 'C', 'D', 'E'] as $label) {
                $this->assertSame(
                    'expected',
                    InboundExpectedLine::query()
                        ->where('inbound_shipment_id', $shipmentId)
                        ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccs[$label]))
                        ->value('status'),
                    "parent {$label} should remain/be expected after addendum union",
                );
            }

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $this->assertSame(5, (int) $shipment->expected_parent_count);

            $this->assertFalse(
                EpcisException::query()
                    ->whereIn('document_id', [(int) $docs['D']->getKey(), (int) $docs['E']->getKey()])
                    ->where('exception_type', 'ASN_SHIPMENT_CORRECTED')
                    ->where('status', 'open')
                    ->exists(),
            );

            $this->assertTrue(
                EpcisException::query()
                    ->whereIn('document_id', array_map(fn ($d) => (int) $d->getKey(), $docs))
                    ->where('exception_type', 'ASN_SHIPMENT_FILE_ADDED')
                    ->where('status', 'open')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function test_correction_subset_file_prunes_unconfirmed_only(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            ExceptionTypeSeeder::ensure('ASN_SHIPMENT_CORRECTED');

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $suffixC = (string) random_int(100000, 999999);
            $asn = 'ASN-CORR3-'.$suffixA;
            $po = 'PO-CORR3-'.$suffixA;

            $ssccA = 'urn:epc:id:sscc:030116.01022'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01022'.$suffixB;
            $ssccC = 'urn:epc:id:sscc:030116.01022'.$suffixC;
            $sgtinA = 'urn:epc:id:sgtin:030116.0200116.4'.$suffixA;
            $sgtinB = 'urn:epc:id:sgtin:030116.0200116.4'.$suffixB;
            $sgtinC = 'urn:epc:id:sgtin:030116.0200116.4'.$suffixC;

            $docA = $this->ingestShippingRefsFixture($ssccA, $sgtinA, $asn, $po);
            $docB = $this->ingestShippingRefsFixture($ssccB, $sgtinB, $asn, $po);
            $docC = $this->ingestShippingRefsFixture($ssccC, $sgtinC, $asn, $po);
            $shipmentId = (int) $docA->inbound_shipment_id;
            $this->assertSame($shipmentId, (int) $docB->inbound_shipment_id);
            $this->assertSame($shipmentId, (int) $docC->inbound_shipment_id);

            $policy = ReceivingPolicy::forTenant($tenant);
            $session = $this->openAsnSession($docA);
            $this->assertTrue(
                app(ConfirmReceivingScan::class)->handle(
                    $session,
                    $ssccA,
                    null,
                    $policy->defaultAutoConfirmChildren(),
                )['ok'],
            );

            // Subset {A,B} with one-parent fixtures: re-ingest B only.
            // A is already confirmed (retained, never pruned); C is pruned as absent.
            $docCorrB = $this->ingestShippingRefsFixture($ssccB, $sgtinB, $asn, $po);

            $this->assertSame($shipmentId, (int) $docCorrB->inbound_shipment_id);

            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccA))
                    ->value('status'),
            );
            $this->assertNotNull(
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccA))
                    ->first(),
            );
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->value('status'),
            );
            $this->assertSame(
                'cancelled',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccC))
                    ->value('status'),
            );

            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $docCorrB->getKey())
                    ->where('exception_type', 'ASN_SHIPMENT_CORRECTED')
                    ->where('status', 'open')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function accept_remaining_does_not_complete_shipment_while_other_expected_remain(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $suffixA = (string) random_int(100000, 999999);
            $suffixB = (string) random_int(100000, 999999);
            $asn = 'ASN-AR-'.$suffixA;
            $po = 'PO-AR-'.$suffixA;
            $ssccA = 'urn:epc:id:sscc:030116.01019'.$suffixA;
            $ssccB = 'urn:epc:id:sscc:030116.01019'.$suffixB;

            $docA = $this->ingestShippingRefsFixture(
                $ssccA,
                'urn:epc:id:sgtin:030116.0200116.9'.$suffixA,
                $asn,
                $po,
            );
            $docB = $this->ingestShippingRefsFixture(
                $ssccB,
                'urn:epc:id:sgtin:030116.0200116.9'.$suffixB,
                $asn,
                $po,
            );
            $shipmentId = (int) $docA->inbound_shipment_id;
            $this->assertSame($shipmentId, (int) $docB->inbound_shipment_id);

            $session = $this->openAsnSession($docA);
            $ssccBEpcId = (int) Epc::query()->where('epc_uri', $ssccB)->value('id');
            $this->assertGreaterThan(0, $ssccBEpcId);

            // Session scoped to this truck only: drop B from session lines/denorm, leave
            // B expected on the ASN shipment (later truck / later session).
            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where(function ($q) use ($ssccBEpcId): void {
                    $q->where('epc_id', $ssccBEpcId)
                        ->orWhere('parent_epc_id', $ssccBEpcId);
                })
                ->delete();

            $parentCount = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'parent')
                ->count();
            $childCount = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'child')
                ->count();
            $session->forceFill([
                'expected_parent_count' => $parentCount,
                'expected_child_count' => $childCount,
                'confirmed_parent_count' => 0,
                'confirmed_child_count' => 0,
            ])->save();

            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->value('status'),
            );

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle($session->fresh());
            $this->assertGreaterThan(0, $result['confirmed']);
            $this->assertSame([], $result['blockers']);

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $this->assertTrue($shipment->hasRemainingExpected());
            $this->assertSame('open', $shipment->status);
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccB))
                    ->value('status'),
            );
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccA))
                    ->value('status'),
            );
            $this->assertSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function expected_inbound_order_header_rollups_match_line_tallies(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $ingested = $this->ingestUniqueShippingRefs();
            $session = $this->openAsnSession($ingested['document']);
            $shipmentId = (int) $session->inbound_shipment_id;

            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertTrue(
                app(ConfirmReceivingScan::class)->handle(
                    $session,
                    $ingested['sscc_uri'],
                    null,
                    $policy->defaultAutoConfirmChildren(),
                )['ok'],
            );

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $header = ExpectedInboundOrderHeader::forSession($session->fresh());
            $this->assertNotNull($header);

            $parentExpected = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('line_role', 'parent')
                ->whereIn('status', ['expected', 'confirmed'])
                ->count();
            $parentConfirmed = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('line_role', 'parent')
                ->where('status', 'confirmed')
                ->count();
            $eachExpected = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('line_role', 'child')
                ->whereIn('status', ['expected', 'confirmed'])
                ->count();
            $eachConfirmed = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('line_role', 'child')
                ->where('status', 'confirmed')
                ->count();

            $this->assertSame($parentExpected, (int) $shipment->expected_parent_count);
            $this->assertSame($parentConfirmed, (int) $shipment->confirmed_parent_count);
            $this->assertSame($eachExpected, (int) $shipment->expected_each_count);
            $this->assertSame($eachConfirmed, (int) $shipment->confirmed_each_count);

            $this->assertSame($parentExpected, $header['parents_expected']);
            $this->assertSame($parentConfirmed, $header['parents_confirmed']);
            $this->assertSame($eachExpected, $header['eaches_expected']);
            $this->assertSame($eachConfirmed, $header['eaches_confirmed']);
            $this->assertSame((string) $shipment->status, $header['status']);
            $this->assertSame($ingested['asn'], $header['asn']);
            $this->assertSame($ingested['po'], $header['po']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{document: EpcisDocument, sscc_uri: string, sgtin_uri: string, asn: string, po: string}
     */
    private function ingestUniqueShippingRefs(): array
    {
        $suffix = (string) random_int(100000, 999999);
        $sscc = 'urn:epc:id:sscc:030116.01017'.$suffix;
        $sgtin = 'urn:epc:id:sgtin:030116.0200116.7'.$suffix;
        $asn = 'ASN-EIO-'.$suffix;
        $po = 'PO-EIO-'.$suffix;

        $document = $this->ingestShippingRefsFixture($sscc, $sgtin, $asn, $po);

        return [
            'document' => $document,
            'sscc_uri' => $sscc,
            'sgtin_uri' => $sgtin,
            'asn' => $asn,
            'po' => $po,
        ];
    }

    private function ingestShippingRefsFixture(
        string $ssccUri,
        string $sgtinUri,
        string $asn,
        string $po,
    ): EpcisDocument {
        $fixture = base_path('tests/Fixtures/epcis/minimal_with_shipping_refs.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_eio_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace(
            [
                '22222222-3333-4444-5555-666666666666',
                self::DEFAULT_SSCC,
                self::DEFAULT_SGTIN,
                'ASN-TEST-4787',
                'PO-TEST-7174',
            ],
            [(string) Str::uuid(), $ssccUri, $sgtinUri, $asn, $po],
            $xml,
        );
        file_put_contents($tmp, $xml);

        $this->epcUris[] = $ssccUri;
        $this->epcUris[] = $sgtinUri;

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_with_shipping_refs.xml',
            ]);
        } finally {
            @unlink($tmp);
        }

        $this->documentIds[] = (int) $document->getKey();
        $this->assertSame('validated', $document->status);
        $this->assertNotNull($document->inbound_shipment_id);

        return $document->fresh();
    }

    private function authorTenantReceiptAtSite(Site $site, Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Receiving,
            'status' => 'parsed',
            'original_filename' => 'custody-asn-receipt-'.Str::random(6).'.xml',
            'notes' => 'Generated receiving EPCIS for ASN custody badge test.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
            'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
            'read_point_gln' => (string) $site->gln,
            'biz_location_gln' => (string) $site->gln,
        ]);

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function openAsnSession(EpcisDocument $document): ReceivingSession
    {
        $siteId = $this->resolveEligibleReceiveSiteId();
        $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
        $this->sessionIds[] = (int) $session->getKey();

        return $session;
    }

    private function resolveEligibleReceiveSiteId(): ?int
    {
        $sites = app(EligibleReceiveSites::class)->options();

        return $sites === [] ? null : (int) array_key_first($sites);
    }

    private function setEdgeMode(Tenant $tenant, ReceivingEdgeMode $mode): void
    {
        TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
        TenantSettings::forTenant($tenant)->setReceivingEdgeMode($mode);
        TenantSettings::forTenant($tenant)->setAllowParallelSessions(false);
        $tenant->save();
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

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);
        if ($tenant->profile !== TenantProfile::Pharmacy) {
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
        }

        tenancy()->initialize($tenant);

        $this->prepareDemo2ReceivingState();

        $settings = TenantSettings::forTenant($tenant);
        $this->priorRequireTi = $settings->requireTiForScanFirst();
        $this->priorEdgeMode = $settings->receivingEdgeMode();
        $this->priorParallel = $settings->allowParallelSessions();
        $settings->setReceivingEdgeMode(null);
        $settings->setAllowParallelSessions(false);
        $tenant->save();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        try {
            $shipmentIds = EpcisDocument::query()
                ->whereIn('id', $this->documentIds)
                ->whereNotNull('inbound_shipment_id')
                ->pluck('inbound_shipment_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $sessionIds = collect($this->sessionIds)
                ->merge(
                    ReceivingSession::query()
                        ->whereIn('epcis_document_id', $this->documentIds)
                        ->when(
                            $shipmentIds !== [] && Schema::hasColumn('receiving_sessions', 'inbound_shipment_id'),
                            fn ($q) => $q->orWhereIn('inbound_shipment_id', $shipmentIds),
                        )
                        ->pluck('id'),
                )
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            foreach ($sessionIds as $sessionId) {
                $session = ReceivingSession::query()->find($sessionId);
                if ($session?->receiving_epcis_document_id !== null) {
                    EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
                }
                ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }

            if ($shipmentIds !== [] && Schema::hasTable('inbound_expected_lines')) {
                InboundExpectedLine::query()
                    ->whereIn('inbound_shipment_id', $shipmentIds)
                    ->delete();
            }

            EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();

            if ($this->documentIds !== []) {
                DB::table('event_epcs')->whereIn(
                    'event_id',
                    DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->select('id'),
                )->delete();
                DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();

                if ($shipmentIds !== []) {
                    EpcisDocument::query()
                        ->whereIn('inbound_shipment_id', $shipmentIds)
                        ->update(['inbound_shipment_id' => null]);
                }

                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }

            if ($shipmentIds !== []) {
                InboundShipment::query()->whereIn('id', $shipmentIds)->delete();
            }

            $this->prepareDemo2ReceivingState();

            if ($this->epcUris !== []) {
                $epcIds = Epc::query()
                    ->whereIn('epc_uri', $this->epcUris)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                if ($epcIds !== []) {
                    ReceivingScanLine::query()->whereIn('epc_id', $epcIds)->delete();
                    DB::table('aggregation_links')
                        ->where(function ($q) use ($epcIds): void {
                            $q->whereIn('parent_epc_id', $epcIds)
                                ->orWhereIn('child_epc_id', $epcIds);
                        })
                        ->delete();
                    DB::table('epc_ilmd')->whereIn('epc_id', $epcIds)->delete();
                    DB::table('event_epcs')->whereIn('epc_id', $epcIds)->delete();
                    DB::table('document_epcs')->whereIn('epc_id', $epcIds)->delete();
                    Epc::query()->whereIn('id', $epcIds)->delete();
                }
            }

            $settings = TenantSettings::forTenant($tenant);
            if ($this->priorRequireTi !== null) {
                $settings->setRequireTiForScanFirst($this->priorRequireTi);
            }
            $settings->setReceivingEdgeMode($this->priorEdgeMode);
            if ($this->priorParallel !== null) {
                $settings->setAllowParallelSessions($this->priorParallel);
            }
            if ($this->priorProfile !== null) {
                $tenant->forceFill(['profile' => $this->priorProfile]);
            }
            $tenant->save();
        } finally {
            $this->documentIds = [];
            $this->sessionIds = [];
            $this->epcUris = [];
            $this->priorRequireTi = null;
            $this->priorEdgeMode = null;
            $this->priorParallel = null;
            $this->priorProfile = null;
            tenancy()->end();
        }
    }
}
