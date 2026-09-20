<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\UnconfirmReceivingScanLine;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Receiving\InboundExpectedLine;
use App\Models\Receiving\InboundShipment;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\InboundExpectedLineClaims;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ParallelReceivingSessionsTest extends TestCase
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

    private ?bool $priorAutoComplete = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function parallel_sessions_setting_off_second_opener_resumes_same_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, false);

            $docs = $this->ingestParents(3, 'OFF')['docs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;

            $sessionA = $this->openAsnSession($docs[0]);
            $sessionB = $this->openAsnSession($docs[0]);

            $this->assertSame((int) $sessionA->getKey(), (int) $sessionB->getKey());
            $this->assertSame($shipmentId, (int) $sessionB->inbound_shipment_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_sessions_setting_on_two_open_sessions_same_shipment(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);

            $docs = $this->ingestParents(3, 'ON2')['docs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;

            $sessionA = $this->openAsnSession($docs[0]);
            $sessionB = $this->openAsnSession($docs[0]);

            $this->assertNotSame((int) $sessionA->getKey(), (int) $sessionB->getKey());
            $this->assertSame($shipmentId, (int) $sessionA->inbound_shipment_id);
            $this->assertSame($shipmentId, (int) $sessionB->inbound_shipment_id);
            $this->assertSame('open', $sessionA->fresh()->status);
            $this->assertSame('open', $sessionB->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_on_cancel_then_reopen_stays_empty_without_claiming_parents(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);

            $docs = $this->ingestParents(3, 'REOP')['docs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;

            $session = $this->openAsnSession($docs[0]);
            $this->assertSame(0, (int) $session->fresh()->expected_parent_count);
            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->count());

            app(CancelReceivingSession::class)->handle($session->fresh());
            $this->assertSame('cancelled', $session->fresh()->status);

            $reopened = $this->openAsnSession($docs[0]);
            $this->assertSame((int) $session->getKey(), (int) $reopened->getKey());
            $this->assertSame('open', $reopened->status);
            $this->assertSame(0, (int) $reopened->expected_parent_count);
            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $reopened->getKey())
                ->count());
            $this->assertSame(
                0,
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('claimed_receiving_session_id', $reopened->getKey())
                    ->count(),
                'Parallel reopen must not claim expected parents until scan',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_off_cancel_then_reopen_reseeds_expected_parents(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, false);

            $docs = $this->ingestParents(2, 'REOF')['docs'];

            $session = $this->openAsnSession($docs[0]);
            $this->assertGreaterThan(0, (int) $session->fresh()->expected_parent_count);

            app(CancelReceivingSession::class)->handle($session->fresh());
            $this->assertSame('cancelled', $session->fresh()->status);

            $reopened = $this->openAsnSession($docs[0]);
            $this->assertSame((int) $session->getKey(), (int) $reopened->getKey());
            $this->assertSame('open', $reopened->status);
            $this->assertGreaterThan(0, (int) $reopened->expected_parent_count);
            $this->assertSame(
                (int) $reopened->expected_parent_count,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $reopened->getKey())
                    ->where('line_role', 'parent')
                    ->where('status', 'expected')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_sessions_setting_on_session_b_does_not_seed_or_confirm_a_claims(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);

            $ingested = $this->ingestParents(4, 'CLM');
            $docs = $ingested['docs'];
            $ssccs = $ingested['ssccs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;

            $sessionA = $this->openAsnSession($docs[0]);
            $this->assertSame(0, (int) $sessionA->fresh()->expected_parent_count);
            // Claim two parents onto A without confirming (B must hit claimed_other_session).
            $this->claimParentsOntoSession($sessionA, $shipmentId, [$ssccs[0], $ssccs[1]]);

            $sessionB = $this->openAsnSession($docs[0]);
            $this->assertNotSame((int) $sessionA->getKey(), (int) $sessionB->getKey());
            $this->assertSame(0, (int) $sessionB->fresh()->expected_parent_count);

            $policy = ReceivingPolicy::forTenant($tenant);
            $blocked = app(ConfirmReceivingScan::class)->handle(
                $sessionB->fresh(),
                $ssccs[0],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertFalse($blocked['ok']);
            $this->assertSame('claimed_other_session', $blocked['effect']);

            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereHas('epc', fn ($q) => $q->where('epc_uri', $ssccs[0]))
                    ->value('status'),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_sessions_setting_on_confirmed_by_a_is_already_received_on_b(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);

            $ingested = $this->ingestParents(3, 'ALR');
            $docs = $ingested['docs'];
            $ssccs = $ingested['ssccs'];

            $sessionA = $this->openAsnSession($docs[0]);
            $sessionB = $this->openAsnSession($docs[0]);
            $policy = ReceivingPolicy::forTenant($tenant);

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $sessionA->fresh(),
                $ssccs[0],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue(
                $confirm['ok'],
                ($confirm['message'] ?? 'A confirm failed').' effect='.($confirm['effect'] ?? ''),
            );

            $rescan = app(ConfirmReceivingScan::class)->handle(
                $sessionB->fresh(),
                $ssccs[0],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($rescan['ok']);
            $this->assertSame('already_received', $rescan['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_empty_session_claims_child_expected_line_instead_of_unexpected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            // Open-count allows unit scans; sealed_parent would reject SGTIN before claim.
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenCount);
            $this->setParallelSessions($tenant, true);

            $ingested = $this->ingestParents(1, 'CHD');
            $docs = $ingested['docs'];
            $ssccs = $ingested['ssccs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;
            $this->assertGreaterThan(0, $shipmentId);

            $parentEpcId = (int) Epc::query()->where('epc_uri', $ssccs[0])->value('id');
            $child = Epc::query()->create(Epc::materializeAttributesFromUri(
                'urn:epc:id:sgtin:030116.0200116.'.(string) random_int(10_000_000_000_000, 99_999_999_999_999),
            ));
            $this->epcUris[] = $child->epc_uri;
            InboundExpectedLine::query()->create([
                'inbound_shipment_id' => $shipmentId,
                'epc_id' => $child->getKey(),
                'parent_epc_id' => $parentEpcId,
                'line_role' => 'child',
                'status' => 'expected',
                'source' => 'epcis_aggregation',
            ]);
            $sgtinUri = $child->epc_uri;

            $session = $this->openAsnSession($docs[0]);
            $this->assertSame(0, (int) $session->fresh()->expected_parent_count);

            $policy = ReceivingPolicy::forTenant($tenant);
            $result = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                $sgtinUri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );

            $this->assertNotSame(
                'unexpected',
                $result['effect'] ?? null,
                'Child expected EPCs must claim onto parallel empty sessions, not fall through as unexpected. Got: '
                .($result['effect'] ?? 'null').' '.$result['message'],
            );

            $childEpcId = (int) $child->getKey();
            $this->assertSame(
                (int) $session->getKey(),
                (int) InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('epc_id', $childEpcId)
                    ->value('claimed_receiving_session_id'),
            );
            $this->assertTrue(
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('epc_id', $childEpcId)
                    ->where('line_role', 'child')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_sessions_setting_on_complete_releases_unconfirmed_claims(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);

            $ingested = $this->ingestParents(3, 'REL');
            $docs = $ingested['docs'];
            $ssccs = $ingested['ssccs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;

            $sessionA = $this->openAsnSession($docs[0]);
            $this->claimParentsOntoSession($sessionA, $shipmentId, [$ssccs[0], $ssccs[1]]);

            $epcId = (int) Epc::query()->where('epc_uri', $ssccs[0])->value('id');
            $this->assertSame(
                (int) $sessionA->getKey(),
                (int) InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('epc_id', $epcId)
                    ->value('claimed_receiving_session_id'),
            );

            // Confirm one so Complete can post; leave ssccs[1] claimed but unconfirmed.
            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertTrue(
                app(ConfirmReceivingScan::class)->handle(
                    $sessionA->fresh(),
                    $ssccs[0],
                    null,
                    $policy->defaultAutoConfirmChildren(),
                )['ok'],
            );

            app(CompleteReceivingSession::class)->handle($sessionA->fresh());

            $releasedEpcId = (int) Epc::query()->where('epc_uri', $ssccs[1])->value('id');
            $this->assertNull(
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('epc_id', $releasedEpcId)
                    ->value('claimed_receiving_session_id'),
            );

            $sessionB = $this->openAsnSession($docs[0]);
            $confirmB = app(ConfirmReceivingScan::class)->handle(
                $sessionB->fresh(),
                $ssccs[1],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirmB['ok'], $confirmB['message'] ?? 'B confirm after release failed');
            $this->assertNotSame('claimed_other_session', $confirmB['effect'] ?? null);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_sessions_day2_after_partial_still_works_with_setting_on(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);

            $ingested = $this->ingestParents(5, 'D2');
            $docs = $ingested['docs'];
            $ssccs = $ingested['ssccs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;
            $policy = ReceivingPolicy::forTenant($tenant);

            $session1 = $this->openAsnSession($docs[0]);
            $this->assertSame(0, (int) $session1->fresh()->expected_parent_count);
            foreach ([$ssccs[0], $ssccs[1]] as $sscc) {
                $this->assertTrue(
                    app(ConfirmReceivingScan::class)->handle(
                        $session1->fresh(),
                        $sscc,
                        null,
                        $policy->defaultAutoConfirmChildren(),
                    )['ok'],
                );
            }
            $this->assertTrue($session1->fresh()->canOperatorCompleteInboundAsn());
            app(CompleteReceivingSession::class)->handle($session1->fresh());

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $this->assertTrue($shipment->hasRemainingExpected());
            $this->assertSame('open', $shipment->status);

            $session2 = $this->openAsnSession($docs[0]);
            $this->assertNotSame((int) $session1->getKey(), (int) $session2->getKey());
            $this->assertSame(0, (int) $session2->expected_parent_count);

            $rescan = app(ConfirmReceivingScan::class)->handle(
                $session2->fresh(),
                $ssccs[0],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertSame('already_received', $rescan['effect']);

            $this->assertTrue(
                app(ConfirmReceivingScan::class)->handle(
                    $session2->fresh(),
                    $ssccs[2],
                    null,
                    $policy->defaultAutoConfirmChildren(),
                )['ok'],
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_on_first_opener_starts_empty_and_completes_after_one_confirm(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);
            TenantSettings::forTenant($tenant)->setAutoCompleteAsnOnReady(true);
            $tenant->save();

            $ingested = $this->ingestParents(3, 'ONE');
            $docs = $ingested['docs'];
            $ssccs = $ingested['ssccs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;
            $policy = ReceivingPolicy::forTenant($tenant);

            $session = $this->openAsnSession($docs[0]);
            $this->assertSame(0, (int) $session->fresh()->expected_parent_count);
            $this->assertFalse($session->fresh()->canOperatorCompleteInboundAsn());

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                $ssccs[0],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'confirm failed');
            // Auto-complete setting is on, but parallel must block auto-complete.
            $this->assertSame('in_progress', $session->fresh()->status);
            $this->assertTrue($session->fresh()->canOperatorCompleteInboundAsn());

            $completed = app(CompleteReceivingSession::class)->handle($session->fresh());
            $this->assertSame('completed', $completed->status);

            $shipment = InboundShipment::query()->findOrFail($shipmentId)->refreshRollups();
            $this->assertTrue($shipment->hasRemainingExpected());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function unconfirm_reclaims_shipment_expected_line_so_parallel_session_cannot_steal(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);

            $ingested = $this->ingestParents(2, 'UCLM');
            $docs = $ingested['docs'];
            $ssccs = $ingested['ssccs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;
            $policy = ReceivingPolicy::forTenant($tenant);

            $sessionA = $this->openAsnSession($docs[0]);
            $this->assertTrue(
                app(ConfirmReceivingScan::class)->handle(
                    $sessionA->fresh(),
                    $ssccs[0],
                    null,
                    $policy->defaultAutoConfirmChildren(),
                )['ok'],
            );

            $parentEpcId = (int) Epc::query()->where('epc_uri', $ssccs[0])->value('id');
            $parentLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $sessionA->getKey())
                ->where('epc_id', $parentEpcId)
                ->where('line_role', 'parent')
                ->where('status', 'confirmed')
                ->firstOrFail();

            app(UnconfirmReceivingScanLine::class)->handle($parentLine->fresh());

            $expectedLine = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('epc_id', $parentEpcId)
                ->firstOrFail();
            $this->assertSame('expected', $expectedLine->status);
            $this->assertNull($expectedLine->confirmed_receiving_session_id);
            $this->assertSame(
                (int) $sessionA->getKey(),
                (int) $expectedLine->claimed_receiving_session_id,
                'Unconfirm must reclaim the expected line for the session that still holds it',
            );

            $sessionB = $this->openAsnSession($docs[0]);
            $this->assertNotSame((int) $sessionA->getKey(), (int) $sessionB->getKey());

            $blocked = app(ConfirmReceivingScan::class)->handle(
                $sessionB->fresh(),
                $ssccs[0],
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertFalse($blocked['ok']);
            $this->assertSame('claimed_other_session', $blocked['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function unconfirm_on_session_b_does_not_revert_shipment_lines_confirmed_by_session_a(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);

            $ingested = $this->ingestParents(2, 'REV');
            $docs = $ingested['docs'];
            $ssccs = $ingested['ssccs'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;
            $policy = ReceivingPolicy::forTenant($tenant);

            $sessionA = $this->openAsnSession($docs[0]);
            $this->assertTrue(
                app(ConfirmReceivingScan::class)->handle(
                    $sessionA->fresh(),
                    $ssccs[0],
                    null,
                    $policy->defaultAutoConfirmChildren(),
                )['ok'],
            );

            $parentAEpcId = (int) Epc::query()->where('epc_uri', $ssccs[0])->value('id');
            $childLineA = InboundExpectedLine::query()
                ->where('inbound_shipment_id', $shipmentId)
                ->where('parent_epc_id', $parentAEpcId)
                ->where('line_role', 'child')
                ->where('status', 'confirmed')
                ->first();
            $this->assertNotNull($childLineA, 'Auto-confirm children should stamp shipment child lines.');
            $this->assertSame((int) $sessionA->getKey(), (int) $childLineA->confirmed_receiving_session_id);
            $childEpcId = (int) $childLineA->epc_id;

            $sessionB = $this->openAsnSession($docs[0]);
            $this->claimParentsOntoSession($sessionB, $shipmentId, [$ssccs[1]]);
            $this->assertTrue(
                app(ConfirmReceivingScan::class)->handle(
                    $sessionB->fresh(),
                    $ssccs[1],
                    null,
                    false,
                )['ok'],
            );

            $parentBEpcId = (int) Epc::query()->where('epc_uri', $ssccs[1])->value('id');
            // Simulate B holding a session child row for an EPC A already confirmed on the shipment.
            ReceivingScanLine::query()->create([
                'receiving_session_id' => $sessionB->getKey(),
                'epc_id' => $childEpcId,
                'parent_epc_id' => $parentBEpcId,
                'line_role' => 'child',
                'status' => 'expected',
            ]);

            $parentBLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $sessionB->getKey())
                ->where('epc_id', $parentBEpcId)
                ->where('line_role', 'parent')
                ->firstOrFail();

            app(UnconfirmReceivingScanLine::class)->handle($parentBLine->fresh());

            $childLineA->refresh();
            $this->assertSame('confirmed', $childLineA->status);
            $this->assertSame(
                (int) $sessionA->getKey(),
                (int) $childLineA->confirmed_receiving_session_id,
                'Unconfirm on B must not clear shipment confirms stamped by A.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function parallel_addendum_expands_every_open_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            $this->setParallelSessions($tenant, true);

            $ingested = $this->ingestParents(2, 'EXP');
            $docs = $ingested['docs'];
            $ssccs = $ingested['ssccs'];
            $asn = $ingested['asn'];
            $po = $ingested['po'];
            $shipmentId = (int) $docs[0]->inbound_shipment_id;

            $sessionA = $this->openAsnSession($docs[0]);
            $this->claimParentsOntoSession($sessionA, $shipmentId, [$ssccs[0]]);

            $sessionB = $this->openAsnSession($docs[0]);
            $this->assertNotSame((int) $sessionA->getKey(), (int) $sessionB->getKey());
            $this->claimParentsOntoSession($sessionB, $shipmentId, [$ssccs[1]]);

            $suffix = (string) random_int(100000, 999999);
            $ssccNew = 'urn:epc:id:sscc:030116.01031'.$suffix;
            $sgtinNew = 'urn:epc:id:sgtin:030116.0200116.6'.$suffix;
            $docAdd = $this->ingestShippingRefsFixture($ssccNew, $sgtinNew, $asn, $po);
            $this->assertSame($shipmentId, (int) $docAdd->inbound_shipment_id);

            $newEpcId = (int) Epc::query()->where('epc_uri', $ssccNew)->value('id');
            $this->assertGreaterThan(0, $newEpcId);
            $this->assertSame(
                'expected',
                InboundExpectedLine::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->where('epc_id', $newEpcId)
                    ->value('status'),
                'Addendum must sync an expected parent line before expand can claim it',
            );

            // Expand-all (oldest first): A and B both receive expand; free addendum
            // parents claim onto the oldest live session that can take them.
            $onA = ReceivingScanLine::query()
                ->where('receiving_session_id', $sessionA->getKey())
                ->where('epc_id', $newEpcId)
                ->where('line_role', 'parent')
                ->exists();
            $onB = ReceivingScanLine::query()
                ->where('receiving_session_id', $sessionB->getKey())
                ->where('epc_id', $newEpcId)
                ->where('line_role', 'parent')
                ->exists();

            $this->assertTrue($onA || $onB, 'Addendum parent must land on at least one open session');
            $this->assertTrue($onA, 'Oldest open session must be expanded (not only newest)');
            $this->assertFalse($onB, 'Exclusive claim: newest must not also hold the addendum parent');

            // Both sessions stay live and were candidates for expand (still open).
            $this->assertSame('open', $sessionA->fresh()->status);
            $this->assertSame('open', $sessionB->fresh()->status);
            $this->assertGreaterThan(
                1,
                ReceivingSession::query()
                    ->where('inbound_shipment_id', $shipmentId)
                    ->whereIn('status', ['open', 'in_progress'])
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{docs: list<EpcisDocument>, ssccs: list<string>, asn: string, po: string}
     */
    private function ingestParents(int $count, string $tag): array
    {
        $base = (string) random_int(100000, 999999);
        $asn = 'ASN-PAR-'.$tag.$base;
        $po = 'PO-PAR-'.$tag.$base;
        $docs = [];
        $ssccs = [];
        for ($i = 0; $i < $count; $i++) {
            $suffix = (string) random_int(100000, 999999);
            $sscc = 'urn:epc:id:sscc:030116.01030'.$suffix;
            $sgtin = 'urn:epc:id:sgtin:030116.0200116.5'.$suffix;
            $ssccs[] = $sscc;
            $docs[] = $this->ingestShippingRefsFixture($sscc, $sgtin, $asn, $po);
        }

        return ['docs' => $docs, 'ssccs' => $ssccs, 'asn' => $asn, 'po' => $po];
    }

    /**
     * Claim selected parents onto an empty parallel session (expected scan lines, no confirm).
     *
     * @param  list<string>  $ssccUris
     */
    private function claimParentsOntoSession(
        ReceivingSession $session,
        int $shipmentId,
        array $ssccUris,
    ): void {
        $epcIds = Epc::query()
            ->whereIn('epc_uri', $ssccUris)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $claimed = InboundExpectedLineClaims::claimExpectedParents($session->fresh(), $epcIds);
        $this->assertNotSame([], $claimed);

        $now = now();
        $rows = [];
        foreach ($claimed as $epcId) {
            $rows[] = [
                'receiving_session_id' => $session->getKey(),
                'epc_id' => $epcId,
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'expected',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        ReceivingScanLine::query()->insert($rows);
        $session->forceFill(['expected_parent_count' => count($claimed)])->save();
    }

    /**
     * @param  list<string>  $keepSsccUris
     */
    private function narrowSessionClaimsToParents(
        ReceivingSession $session,
        int $shipmentId,
        array $keepSsccUris,
    ): void {
        $this->claimParentsOntoSession($session, $shipmentId, $keepSsccUris);
    }

    private function ingestShippingRefsFixture(
        string $ssccUri,
        string $sgtinUri,
        string $asn,
        string $po,
    ): EpcisDocument {
        $fixture = base_path('tests/Fixtures/epcis/minimal_with_shipping_refs.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_par_');
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

        return $document->fresh();
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
        $tenant->save();
    }

    private function setParallelSessions(Tenant $tenant, bool $enabled): void
    {
        TenantSettings::forTenant($tenant)->setAllowParallelSessions($enabled);
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
        $this->priorAutoComplete = $settings->autoCompleteAsnOnReady();
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
            if ($this->priorAutoComplete !== null) {
                $settings->setAutoCompleteAsnOnReady($this->priorAutoComplete);
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
            $this->priorAutoComplete = null;
            $this->priorProfile = null;
            tenancy()->end();
        }
    }
}
