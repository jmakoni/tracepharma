<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Actions\Receiving\UnconfirmReceivingScanLine;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPackShape;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

/**
 * Receive SOP parity: barcode + AggregationLink inference only (no count gates).
 * Open-tote locking / comingling covered by OpenToteReceivingTest.
 */
class ReceivingEdgeModeParityTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?string $ssccUri = null;

    private ?string $sgtinUri = null;

    /** @var list<int> */
    private array $extraEpcIds = [];

    /** @var list<int> */
    private array $extraLinkIds = [];

    private ?bool $priorRequireTi = null;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function sealed_parent_allows_sscc_rejects_sgtin_and_auto_confirms_children(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $ingested = $this->ingestUniqueMinimalFixture();
            $session = $this->openAsnSession($ingested['document']);
            $policy = ReceivingPolicy::forTenant($tenant);

            $this->assertTrue($policy->operatorScansSsccOnly());
            $this->assertTrue($policy->defaultAutoConfirmChildren());

            $reject = app(ConfirmReceivingScan::class)->handle(
                $session,
                $this->sgtinUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertFalse($reject['ok']);
            $this->assertStringContainsString('SSCC only', $reject['message']);

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $this->reloadSession(),
                $this->ssccUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');
            $this->assertSame('confirmed', $this->lineStatus($this->sgtinUri));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function tote_lpn_allows_sscc_and_auto_confirms_children(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::ToteLpn);

            $ingested = $this->ingestUniqueMinimalFixture();
            $session = $this->openAsnSession($ingested['document']);
            $policy = ReceivingPolicy::forTenant($tenant);

            $this->assertSame(ReceivingEdgeMode::ToteLpn, $policy->edgeMode());
            $this->assertTrue($policy->defaultAutoConfirmChildren());
            $this->assertFalse($policy->operatorScansSsccOnly());

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                $this->ssccUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'tote confirm failed');
            $this->assertSame('confirmed', $this->lineStatus($this->ssccUri));
            $this->assertSame('confirmed', $this->lineStatus($this->sgtinUri));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function case_only_allows_case_sscc_rejects_pallet_and_leaf_and_auto_confirms_under_case(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::CaseOnly);

            $ingested = $this->ingestUniqueMinimalFixture();
            $session = $this->openAsnSession($ingested['document']);
            $policy = ReceivingPolicy::forTenant($tenant);

            $this->assertTrue($policy->operatorScansCaseOnly());
            $this->assertTrue($policy->defaultAutoConfirmChildren());

            $caseEpc = Epc::query()->where('epc_uri', $this->ssccUri)->firstOrFail();
            $this->assertTrue(ReceivingPackShape::isCasePack($caseEpc, $session));
            $this->assertFalse(ReceivingPackShape::isLogisticsPalletSscc($caseEpc, $session));

            $leafReject = app(ConfirmReceivingScan::class)->handle(
                $session,
                $this->sgtinUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertFalse($leafReject['ok']);
            $this->assertStringContainsString('case', strtolower((string) $leafReject['message']));

            $pallet = $this->createPalletOverCase($caseEpc);
            $palletReject = app(ConfirmReceivingScan::class)->handle(
                $this->reloadSession(),
                $pallet->epc_uri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertFalse($palletReject['ok']);
            $this->assertSame('missing_aggregation', $palletReject['effect']);

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $this->reloadSession(),
                $this->ssccUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'case confirm failed');
            $this->assertSame('confirmed', $this->lineStatus($this->ssccUri));
            $this->assertSame('confirmed', $this->lineStatus($this->sgtinUri));
            // case_only does not require scanning the outer pallet.
            $this->assertNull(
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $pallet->getKey())
                    ->where('status', 'confirmed')
                    ->first(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function case_sop_infers_only_file_children_not_warehouse_links(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::CaseOnly);

            $ingested = $this->ingestUniqueMinimalFixture();
            $session = $this->openAsnSession($ingested['document']);
            $policy = ReceivingPolicy::forTenant($tenant);
            $caseEpc = Epc::query()->where('epc_uri', $this->ssccUri)->firstOrFail();

            $warehouseOnly = $this->createSgtinEpc();
            $link = AggregationLink::query()->create([
                'parent_epc_id' => $caseEpc->getKey(),
                'child_epc_id' => $warehouseOnly->getKey(),
                'established_by_event_id' => null,
                'link_type' => 'aggregation',
                'valid_from' => now(),
                'valid_to' => null,
            ]);
            $this->extraLinkIds[] = (int) $link->getKey();

            $this->assertTrue(ReceivingPackShape::isCasePack($caseEpc, $session));
            $this->assertFalse(ReceivingPackShape::isCasePack($caseEpc));

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                $this->ssccUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'case confirm failed');
            $this->assertSame('confirmed', $this->lineStatus($this->sgtinUri));
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $warehouseOnly->getKey())
                    ->count(),
            );

            $warehouseCase = $this->createSsccEpc();
            $warehouseChild = $this->createSgtinEpc();
            $warehouseLink = AggregationLink::query()->create([
                'parent_epc_id' => $warehouseCase->getKey(),
                'child_epc_id' => $warehouseChild->getKey(),
                'established_by_event_id' => null,
                'link_type' => 'aggregation',
                'valid_from' => now(),
                'valid_to' => null,
            ]);
            $this->extraLinkIds[] = (int) $warehouseLink->getKey();

            $missing = app(ConfirmReceivingScan::class)->handle(
                $this->reloadSession(),
                $warehouseCase->epc_uri,
                null,
                true,
            );
            $this->assertFalse($missing['ok']);
            $this->assertSame('missing_aggregation', $missing['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function case_only_covering_asn_outer_pallet_marks_shipment_expected_line_confirmed(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::CaseOnly);

            $ingested = $this->ingestUniqueMinimalFixture();
            $session = $this->openAsnSession($ingested['document']);
            $policy = ReceivingPolicy::forTenant($tenant);
            $shipmentId = (int) $session->inbound_shipment_id;
            $this->assertGreaterThan(0, $shipmentId);

            $caseEpc = Epc::query()->where('epc_uri', $this->ssccUri)->firstOrFail();
            $pallet = $this->createPalletOverCase($caseEpc);
            $aggregationEvent = EpcisEvent::query()->create([
                'document_id' => $ingested['document']->getKey(),
                'event_type' => 'AggregationEvent',
                'action' => 'ADD',
                'event_time' => now(),
                'record_time' => now(),
            ]);
            AggregationLink::query()
                ->where('parent_epc_id', $pallet->getKey())
                ->where('child_epc_id', $caseEpc->getKey())
                ->update(['established_by_event_id' => $aggregationEvent->getKey()]);

            // Nest the ASN case under the outer pallet on the shipment expected set.
            InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('epc_id', $caseEpc->getKey())
                ->update(['parent_epc_id' => $pallet->getKey()]);

            InboundExpectedLine::query()->create([
                'inbound_shipment_id' => $shipmentId,
                'epc_id' => $pallet->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'expected',
                'source' => 'epcis_aggregation',
            ]);
            ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $pallet->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'expected',
            ]);

            // Drop seeded case line so rematerialize picks up parent_epc_id from shipment.
            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $caseEpc->getKey())
                ->delete();
            $session->forceFill([
                'expected_parent_count' => (int) ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('line_role', 'parent')
                    ->whereIn('status', ['expected', 'confirmed'])
                    ->count(),
            ])->save();

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                $this->ssccUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'case confirm failed');

            $caseLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->where('epc_id', $caseEpc->getKey())
                ->first();
            $this->assertNotNull($caseLine);
            $this->assertSame((int) $pallet->getKey(), (int) $caseLine->parent_epc_id);

            $this->assertSame(
                'confirmed',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $pallet->getKey())
                    ->value('status'),
            );
            $this->assertSame(
                'confirmed',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('epc_id', $pallet->getKey())
                    ->value('status'),
                'Outer pallet cover must confirm the shipment expected line so later sessions cannot receive it again.',
            );

            $coverSignal = EpcisException::query()
                ->where('document_id', $ingested['document']->getKey())
                ->where('exception_type', 'CASE_ONLY_PALLET_COVERED')
                ->where('status', 'open')
                ->first();
            $this->assertNotNull($coverSignal, 'Case-only pallet cover should emit a soft operational exception');
            $this->assertSame((int) $pallet->getKey(), (int) $coverSignal->epc_id);
            $this->assertStringContainsString('without pallet scan', (string) $coverSignal->description);

            // Unconfirming the nested case must uncover the auto-covered outer pallet.
            $this->assertNull(
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $pallet->getKey())
                    ->value('scan_raw'),
                'Auto-cover must leave outer pallet scan_raw empty',
            );

            app(UnconfirmReceivingScanLine::class)->handle($caseLine->fresh());

            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $pallet->getKey())
                    ->value('status'),
                'Outer pallet auto-cover must reverse when nested case is unconfirmed',
            );
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('epc_id', $pallet->getKey())
                    ->value('status'),
                'Shipment expected line for auto-covered pallet must revert on case unconfirm',
            );
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $caseEpc->getKey())
                    ->value('status'),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function case_only_outer_cover_uses_inbound_parent_not_warehouse_link(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::CaseOnly);

            $ingested = $this->ingestUniqueMinimalFixture();
            $session = $this->openAsnSession($ingested['document']);
            $policy = ReceivingPolicy::forTenant($tenant);
            $shipmentId = (int) $session->inbound_shipment_id;
            $this->assertGreaterThan(0, $shipmentId);

            $caseEpc = Epc::query()->where('epc_uri', $this->ssccUri)->firstOrFail();
            $inboundPallet = $this->createPalletOverCase($caseEpc);
            $warehousePallet = $this->createPalletOverCase($caseEpc);

            $aggregationEvent = EpcisEvent::query()->create([
                'document_id' => $ingested['document']->getKey(),
                'event_type' => 'AggregationEvent',
                'action' => 'ADD',
                'event_time' => now(),
                'record_time' => now(),
            ]);
            AggregationLink::query()
                ->where('parent_epc_id', $inboundPallet->getKey())
                ->where('child_epc_id', $caseEpc->getKey())
                ->update(['established_by_event_id' => $aggregationEvent->getKey()]);

            InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('epc_id', $caseEpc->getKey())
                ->update(['parent_epc_id' => $inboundPallet->getKey()]);

            InboundExpectedLine::query()->create([
                'inbound_shipment_id' => $shipmentId,
                'epc_id' => $inboundPallet->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'expected',
                'source' => 'epcis_aggregation',
            ]);
            ReceivingScanLine::query()->create([
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $inboundPallet->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'expected',
            ]);

            ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', $caseEpc->getKey())
                ->delete();
            $session->forceFill([
                'expected_parent_count' => (int) ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('line_role', 'parent')
                    ->whereIn('status', ['expected', 'confirmed'])
                    ->count(),
            ])->save();

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                $this->ssccUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'case confirm failed');

            $caseLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->where('epc_id', $caseEpc->getKey())
                ->first();
            $this->assertNotNull($caseLine);
            $this->assertSame((int) $inboundPallet->getKey(), (int) $caseLine->parent_epc_id);
            $this->assertSame(
                'confirmed',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $inboundPallet->getKey())
                    ->value('status'),
            );
            $this->assertNull(
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $warehousePallet->getKey())
                    ->value('status'),
                'Warehouse-only decoy parent must not be covered.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function open_count_allows_sscc_then_sgtin_without_auto_confirm_on_parent(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenCount);

            $ingested = $this->ingestUniqueMinimalFixture();
            $session = $this->openAsnSession($ingested['document']);
            $policy = ReceivingPolicy::forTenant($tenant);

            $this->assertFalse($policy->defaultAutoConfirmChildren());

            $parent = app(ConfirmReceivingScan::class)->handle(
                $session,
                $this->ssccUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parent['ok'], $parent['message'] ?? 'parent confirm failed');
            $this->assertSame('expected', $this->lineStatus($this->sgtinUri));

            $child = app(ConfirmReceivingScan::class)->handle(
                $this->reloadSession(),
                $this->sgtinUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($child['ok'], $child['message'] ?? 'child confirm failed');
            $this->assertSame('confirmed', $this->lineStatus($this->sgtinUri));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function units_only_allows_sgtin_rejects_sscc_and_does_not_infer(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::UnitsOnly);

            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertTrue($policy->operatorScansUnitsOnly());
            $this->assertFalse($policy->defaultAutoConfirmChildren());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $unit = $this->createSgtinEpc();
            $sscc = $this->createSsccEpc();

            $ssccReject = app(ConfirmReceivingScan::class)->handle(
                $session,
                $sscc->epc_uri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertFalse($ssccReject['ok']);
            $this->assertStringContainsString('unit 2D', (string) $ssccReject['message']);

            $unitConfirm = app(ConfirmReceivingScan::class)->handle(
                $this->reloadSession(),
                $unit->epc_uri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($unitConfirm['ok'], $unitConfirm['message'] ?? 'unit confirm failed');

            $unitLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->where('epc_id', $unit->getKey())
                ->first();
            $this->assertNotNull($unitLine);
            $this->assertSame('confirmed', $unitLine->status);

            // No sealed-parent inference: SSCC never confirmed / no child seeding from SSCC.
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $sscc->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function sealed_mode_with_auto_confirm_rejects_missing_aggregation_children(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertTrue($policy->requiresAggregationChildrenOnConfirm());
            $this->assertTrue($policy->defaultAutoConfirmChildren());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $orphanSscc = $this->createSsccEpc();
            $this->assertSame(0, ReceivingPackShape::openChildCount($orphanSscc));

            $result = app(ConfirmReceivingScan::class)->handle(
                $session,
                $orphanSscc->epc_uri,
                null,
                true,
            );

            $this->assertFalse($result['ok']);
            $this->assertSame('missing_aggregation', $result['effect']);
            $this->assertStringContainsString('aggregation', strtolower((string) $result['message']));
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $orphanSscc->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function changing_edge_mode_changes_rejection_without_code_change(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $ingested = $this->ingestUniqueMinimalFixture();
            $session = $this->openAsnSession($ingested['document']);

            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $sealedReject = app(ConfirmReceivingScan::class)->handle(
                $session,
                $this->sgtinUri,
                null,
                true,
            );
            $this->assertFalse($sealedReject['ok']);
            $this->assertStringContainsString('SSCC only', $sealedReject['message']);

            $this->setEdgeMode($tenant, ReceivingEdgeMode::UnitsOnly);
            $this->assertSame(
                'Units only — receive policy',
                ReceivingPolicy::forTenant($tenant)->edgeMode()->chipLabel(),
            );

            $unitsReject = app(ConfirmReceivingScan::class)->handle(
                $this->reloadSession(),
                $this->ssccUri,
                null,
                false,
            );
            $this->assertFalse($unitsReject['ok']);
            $this->assertStringContainsString('unit 2D', (string) $unitsReject['message']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function setEdgeMode(Tenant $tenant, ReceivingEdgeMode $mode): void
    {
        TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
        TenantSettings::forTenant($tenant)->setReceivingEdgeMode($mode);
        $tenant->save();
    }

    private function openAsnSession(EpcisDocument $document): ReceivingSession
    {
        $siteId = $this->resolveEligibleReceiveSiteId();
        $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
        $this->sessionId = (int) $session->getKey();

        return $session;
    }

    private function reloadSession(): ReceivingSession
    {
        $this->assertNotNull($this->sessionId);

        return ReceivingSession::query()->findOrFail($this->sessionId);
    }

    private function lineStatus(string $epcUri): ?string
    {
        $epcId = Epc::query()->where('epc_uri', $epcUri)->value('id');

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->sessionId)
            ->where('epc_id', $epcId)
            ->value('status');
    }

    private function createPalletOverCase(Epc $caseEpc): Epc
    {
        $pallet = $this->createSsccEpc();
        $link = AggregationLink::query()->create([
            'parent_epc_id' => $pallet->getKey(),
            'child_epc_id' => $caseEpc->getKey(),
            'established_by_event_id' => null,
            'link_type' => 'aggregation',
            'valid_from' => now(),
            'valid_to' => null,
        ]);
        $this->extraLinkIds[] = (int) $link->getKey();

        return $pallet;
    }

    private function createSsccEpc(): Epc
    {
        do {
            $serial = '0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $uri = 'urn:epc:id:sscc:030116.'.$serial;
        } while (Epc::query()->where('epc_uri', $uri)->exists());

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->extraEpcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createSgtinEpc(): Epc
    {
        do {
            $serial = (string) random_int(10_000_000_000_000, 99_999_999_999_999);
            $uri = 'urn:epc:id:sgtin:030116.0200116.'.$serial;
        } while (Epc::query()->where('epc_uri', $uri)->exists());

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->extraEpcIds[] = (int) $epc->getKey();

        return $epc;
    }

    /**
     * @return array{document: EpcisDocument, sscc_uri: string, sgtin_uri: string}
     */
    private function ingestUniqueMinimalFixture(): array
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        do {
            $ssccUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (Epc::query()->where('epc_uri', $ssccUri)->exists());

        do {
            $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.'.(string) random_int(10_000_000_000_000, 99_999_999_999_999);
        } while (Epc::query()->where('epc_uri', $sgtinUri)->exists());

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace(
            [
                '11111111-2222-3333-4444-555555555555',
                'urn:epc:id:sscc:030116.01001227052',
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
            ],
            [(string) Str::uuid(), $ssccUri, $sgtinUri],
            $xml,
        );
        file_put_contents($tmp, $xml);

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }

        $this->documentId = (int) $document->getKey();
        $this->ssccUri = $ssccUri;
        $this->sgtinUri = $sgtinUri;

        $ssccId = (int) Epc::query()->where('epc_uri', $ssccUri)->value('id');
        $sgtinId = (int) Epc::query()->where('epc_uri', $sgtinUri)->value('id');
        if ($ssccId > 0) {
            $this->extraEpcIds[] = $ssccId;
        }
        if ($sgtinId > 0) {
            $this->extraEpcIds[] = $sgtinId;
        }

        return [
            'document' => $document,
            'sscc_uri' => $ssccUri,
            'sgtin_uri' => $sgtinUri,
        ];
    }

    private function resolveEligibleReceiveSiteId(): ?int
    {
        $sites = app(EligibleReceiveSites::class)->options();

        return $sites === [] ? null : (int) array_key_first($sites);
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
        $settings->setReceivingEdgeMode(null);
        $tenant->save();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionId !== null) {
            $session = ReceivingSession::query()->find($this->sessionId);
            if ($session?->receiving_epcis_document_id !== null) {
                EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
            }
            ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->extraLinkIds !== []) {
            AggregationLink::query()->whereIn('id', $this->extraLinkIds)->delete();
            $this->extraLinkIds = [];
        }

        if ($this->documentId !== null) {
            ReceivingScanLine::query()
                ->whereIn(
                    'receiving_session_id',
                    ReceivingSession::query()->where('epcis_document_id', $this->documentId)->select('id'),
                )
                ->delete();
            ReceivingSession::query()->where('epcis_document_id', $this->documentId)->delete();
            DB::table('event_epcs')->whereIn(
                'event_id',
                DB::table('epcis_events')->where('document_id', $this->documentId)->select('id'),
            )->delete();
            DB::table('epcis_events')->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        $this->prepareDemo2ReceivingState();

        if ($this->extraEpcIds !== []) {
            AggregationLink::query()
                ->where(function ($q): void {
                    $q->whereIn('parent_epc_id', $this->extraEpcIds)
                        ->orWhereIn('child_epc_id', $this->extraEpcIds);
                })
                ->delete();
            ReceivingScanLine::query()->whereIn('epc_id', $this->extraEpcIds)->delete();
            InboundExpectedLine::query()->whereIn('epc_id', $this->extraEpcIds)->delete();
            DB::table('epc_ilmd')->whereIn('epc_id', $this->extraEpcIds)->delete();
            DB::table('event_epcs')->whereIn('epc_id', $this->extraEpcIds)->delete();
            DB::table('document_epcs')->whereIn('epc_id', $this->extraEpcIds)->delete();
            Epc::query()->whereIn('id', $this->extraEpcIds)->delete();
            $this->extraEpcIds = [];
        }

        $this->ssccUri = null;
        $this->sgtinUri = null;

        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorRequireTi !== null) {
            $settings->setRequireTiForScanFirst($this->priorRequireTi);
        }
        $settings->setReceivingEdgeMode($this->priorEdgeMode);
        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile]);
        }
        $tenant->save();
        $this->priorRequireTi = null;
        $this->priorEdgeMode = null;
        $this->priorProfile = null;

        tenancy()->end();
    }
}
