<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\ConfirmRemainingExpectedReceivingLines;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTypeCategory;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ReceiveModeAcceptRemainingTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $holdIds = [];

    /** @var list<int> */
    private array $extraEpcIds = [];

    private ?bool $priorRequireTi = null;

    private ?bool $priorRequireAcceptRemainingReason = null;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function sealed_mode_auto_confirms_children_on_parent_scan(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertSame(ReceivingEdgeMode::SealedParent, $policy->edgeMode());
            $this->assertTrue($policy->defaultAutoConfirmChildren());

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );

            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $child = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->where('epc_id', Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id'))
                ->first();

            $this->assertNotNull($child);
            $this->assertSame('child', $child->line_role);
            $this->assertSame('confirmed', $child->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function sealed_mode_rejects_case_sgtin_operator_scan(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $this->assertTrue(ReceivingPolicy::forTenant($tenant)->operatorScansSsccOnly());

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SGTIN_URI,
                null,
                true,
            );

            $this->assertFalse($confirm['ok']);
            $this->assertSame('sscc_only', $confirm['effect']);
            $this->assertStringContainsString('SSCC only', $confirm['message']);

            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id'))
                    ->whereIn('status', ['confirmed', 'unexpected'])
                    ->whereNotNull('scan_raw')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function open_count_does_not_auto_confirm_children_on_parent_scan(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenCount);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertSame(ReceivingEdgeMode::OpenCount, $policy->edgeMode());
            $this->assertFalse($policy->defaultAutoConfirmChildren());

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );

            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $child = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->where('epc_id', Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id'))
                ->first();

            $this->assertNotNull($child);
            $this->assertSame('child', $child->line_role);
            $this->assertSame('expected', $child->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function open_count_accept_remaining_confirms_children_and_completes(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenCount);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $this->assertFalse(ReceivingPolicy::forTenant($tenant)->defaultAutoConfirmChildren());

            $policy = ReceivingPolicy::forTenant($tenant);
            $parentScan = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentScan['ok'], $parentScan['message'] ?? 'parent confirm failed');

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle($session->fresh());

            $this->assertGreaterThanOrEqual(1, $result['confirmed']);
            $this->assertSame([], $result['blockers'], implode(' | ', $result['blockers']));

            $child = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->where('epc_id', Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id'))
                ->first();

            $this->assertNotNull($child);
            $this->assertSame('child', $child->line_role);
            $this->assertSame('confirmed', $child->status);

            $session = $session->fresh();
            if ($session->status !== 'completed') {
                $session = app(CompleteReceivingSession::class)->handle($session);
            }
            $this->assertSame('completed', $session->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function open_count_accept_remaining_confirms_leftover_children_after_parent_scan(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenCount);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            $parentScan = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentScan['ok'], $parentScan['message'] ?? 'parent confirm failed');

            $childId = Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id');
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $childId)
                    ->value('status'),
            );

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle($session->fresh());

            $this->assertGreaterThanOrEqual(1, $result['confirmed']);
            $this->assertSame(
                'confirmed',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', $childId)
                    ->value('status'),
            );

            $session = $session->fresh();
            $this->assertSame('completed', $session->status);

            $secondPass = app(ConfirmRemainingExpectedReceivingLines::class)->handle($session->fresh());
            $this->assertSame(0, $secondPass['confirmed']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function receive_all_expected_files_shortage_and_does_not_uri_confirm_unscanned_parents(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setAllowAutoReceiveWholeAsn(true);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $secondParent = $this->createSsccParentLine($session, 'expected');
            $session->increment('expected_parent_count');

            $hud = (string) file_get_contents(base_path(
                'app/Filament/App/Resources/ReceivingSessions/Concerns/InteractsWithReceivingSessionHud.php',
            ));
            $this->assertStringContainsString("Action::make('receiveAllExpected')", $hud);
            $this->assertStringContainsString('ConfirmRemainingExpectedReceivingLines::class', $hud);
            $receiveAllBlock = substr($hud, (int) strpos($hud, "Action::make('receiveAllExpected')"));
            $receiveAllBlock = substr($receiveAllBlock, 0, (int) strpos($receiveAllBlock, "Action::make('completeReceiving')"));
            $this->assertStringNotContainsString('ConfirmReceivingScan::class', $receiveAllBlock);

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle(
                $session->fresh(),
                reason: 'Receive all expected: unscanned expected parent container(s).',
                sealAcknowledged: true,
            );
            $this->assertNotEmpty($result['blockers']);

            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', Epc::query()->where('epc_uri', self::SSCC_URI)->value('id'))
                    ->value('status'),
            );
            $this->assertSame('expected', $secondParent->fresh()->status);
            $this->assertNotSame('completed', $session->fresh()->status);

            $this->assertGreaterThan(
                0,
                ExceptionCase::query()
                    ->whereHas('type', fn ($q) => $q->whereIn('code', ['DATA_NO_PRODUCT', 'SHORTAGE']))
                    ->count(),
            );
            $this->assertFalse(
                $this->epcAppearsOnSessionReceivingEvent(
                    $session->fresh(),
                    (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id'),
                ),
                'Unscanned parents must not be attested on receiving EPCIS.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function receive_all_expected_confirms_inbound_children_of_scanned_parent_only_and_shortages_unscanned(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setAllowAutoReceiveWholeAsn(true);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $secondParent = $this->createSsccParentLine($session, 'expected');
            $session->increment('expected_parent_count');

            $policy = ReceivingPolicy::forTenant($tenant);
            $parentScan = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentScan['ok'], $parentScan['message'] ?? 'parent confirm failed');

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle(
                $session->fresh(),
                reason: 'Receive all expected: unscanned expected parent container(s).',
                sealAcknowledged: true,
            );

            $this->assertNotEmpty($result['blockers']);
            $this->assertGreaterThanOrEqual(1, $result['skipped']);
            $this->assertSame('expected', $secondParent->fresh()->status);
            $this->assertNotSame('completed', $session->fresh()->status);
            $this->assertNull($session->fresh()->receiving_epcis_document_id);

            $this->assertSame(
                'confirmed',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', Epc::query()->where('epc_uri', self::SSCC_URI)->value('id'))
                    ->value('status'),
            );
            $this->assertSame(
                'confirmed',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id'))
                    ->value('status'),
            );

            $shortage = ExceptionCase::query()
                ->whereHas('type', fn ($q) => $q->whereIn('code', ['DATA_NO_PRODUCT', 'SHORTAGE']))
                ->latest('id')
                ->first();
            $this->assertNotNull($shortage);
            $this->assertSame('DATA_NO_PRODUCT', $shortage->type?->code);
            $this->assertTrue(
                $shortage->activities()
                    ->where('meta->reason', 'PARTIAL_SHIPMENT_UNDECLARED')
                    ->exists(),
                'Undeclared partial is a reason on DATA_NO_PRODUCT, not a dock type.',
            );
            $this->assertTrue(
                $shortage->epcs()->whereKey($secondParent->epc_id)->exists(),
                'Shortage case must attach the unscanned parent EPC.',
            );
            $this->assertFalse(
                $this->epcAppearsOnSessionReceivingEvent($session->fresh(), (int) $secondParent->epc_id),
                'Unscanned parent must not appear on a receiving ObjectEvent.',
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function receive_all_expected_completes_when_all_expected_parents_already_scanned(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);
            TenantSettings::forTenant($tenant)->setAllowAutoReceiveWholeAsn(true);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            $parentScan = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentScan['ok'], $parentScan['message'] ?? 'parent confirm failed');

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle(
                $session->fresh(),
                reason: 'Receive all expected: unscanned expected parent container(s).',
                sealAcknowledged: true,
            );

            $this->assertSame([], $result['blockers'], implode(' | ', $result['blockers']));
            $session = $session->fresh();
            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->assertTrue(
                $this->epcAppearsOnSessionReceivingEvent(
                    $session->fresh(),
                    (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id'),
                ),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function accept_remaining_confirms_expected_parents_skips_quarantine_and_ignores_unexpected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $secondParent = $this->createSsccParentLine($session, 'expected');
            $quarantinedParent = $this->createSsccParentLine($session, 'expected');
            $unexpectedParent = $this->createSsccParentLine($session, 'unexpected');

            $session->increment('expected_parent_count', 2);

            $hold = QuarantineHold::query()->create([
                'epc_id' => $quarantinedParent->epc_id,
                'reason' => 'Accept-remaining skip',
                'status' => 'open',
                'severity' => 'error',
                'opened_at' => now(),
            ]);
            $this->holdIds[] = (int) $hold->getKey();

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle($session->fresh());

            $this->assertSame(0, $result['confirmed']);
            $this->assertSame(3, $result['skipped']);
            $this->assertNotEmpty($result['blockers']);

            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', Epc::query()->where('epc_uri', self::SSCC_URI)->value('id'))
                    ->value('status'),
            );
            $this->assertSame('expected', $secondParent->fresh()->status);
            $this->assertSame('expected', $quarantinedParent->fresh()->status);
            $this->assertSame('unexpected', $unexpectedParent->fresh()->status);

            $this->assertGreaterThan(
                0,
                ExceptionCase::query()
                    ->whereHas('type', fn ($q) => $q->whereIn('code', ['DATA_NO_PRODUCT', 'SHORTAGE']))
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function accept_remaining_last_parent_honors_unpack(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $this->assertTrue(ReceivingPolicy::forTenant($tenant)->canUnpackAtReceive());

            $policy = ReceivingPolicy::forTenant($tenant);
            $parentScan = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentScan['ok'], $parentScan['message'] ?? 'parent confirm failed');

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle(
                $session->fresh(),
                null,
                unpack: true,
            );

            $this->assertSame([], $result['blockers'], implode(' | ', $result['blockers']));
            $session = $session->fresh();
            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->receiving_epcis_document_id);
            $this->assertTrue(
                DB::table('epcis_events')
                    ->where('document_id', $session->receiving_epcis_document_id)
                    ->where('biz_step', 'urn:epcglobal:cbv:bizstep:unpacking')
                    ->exists(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function accept_remaining_reports_blocker_and_confirms_none_when_document_is_hard_blocked(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->ensureUnknownGtinExceptionType();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::SealedParent);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $unknownGtin = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();
            $unknownGtin->forceFill(['receive_impact' => ExceptionReceiveImpact::BusinessRule])->save();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $unknownGtin->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Blocks accept remaining',
                'description' => 'Document-wide block',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle($session->fresh());

            $this->assertSame(0, $result['confirmed']);
            $this->assertSame(0, $result['skipped']);
            $this->assertNotEmpty($result['blockers']);

            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('line_role', 'parent')
                    ->where('epc_id', Epc::query()->where('epc_uri', self::SSCC_URI)->value('id'))
                    ->value('status'),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function accept_remaining_without_reason_still_works_when_reason_setting_off(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenCount);
            TenantSettings::forTenant($tenant)->setRequireAcceptRemainingReason(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            $parentScan = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentScan['ok'], $parentScan['message'] ?? 'parent confirm failed');

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle($session->fresh());

            $this->assertGreaterThanOrEqual(1, $result['confirmed']);
            $this->assertSame([], $result['blockers'], implode(' | ', $result['blockers']));
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function accept_remaining_blocked_without_reason_when_reason_setting_on(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenCount);
            TenantSettings::forTenant($tenant)->setRequireAcceptRemainingReason(true);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            try {
                app(ConfirmRemainingExpectedReceivingLines::class)->handle($session->fresh());
                $this->fail('Expected DomainException when accept-remaining reason is required');
            } catch (DomainException $e) {
                $this->assertStringContainsString('reason is required', strtolower($e->getMessage()));
            }

            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->whereIn('status', ['confirmed', 'unexpected'])
                    ->count(),
            );
            $this->assertGreaterThan(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('status', 'expected')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function accept_remaining_succeeds_with_reason_when_reason_setting_on(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenCount);
            TenantSettings::forTenant($tenant)->setRequireAcceptRemainingReason(true);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            Log::spy();

            $policy = ReceivingPolicy::forTenant($tenant);
            $parentScan = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentScan['ok'], $parentScan['message'] ?? 'parent confirm failed');

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle(
                $session->fresh(),
                reason: 'Dock shortage close — remaining cases not on truck',
            );

            $this->assertGreaterThanOrEqual(1, $result['confirmed']);
            $this->assertSame([], $result['blockers'], implode(' | ', $result['blockers']));

            Log::shouldHaveReceived('info')
                ->withArgs(function (string $message, array $context): bool {
                    return $message === 'receiving.session.accept_remaining'
                        && ($context['reason'] ?? null) === 'Dock shortage close — remaining cases not on truck';
                })
                ->atLeast()
                ->once();

            $activity = Activity::query()
                ->where('description', 'receiving_accept_remaining')
                ->where('subject_type', ReceivingSession::class)
                ->where('subject_id', $this->sessionId)
                ->latest('id')
                ->first();

            $this->assertNotNull($activity);
            $this->assertSame(
                'Dock shortage close — remaining cases not on truck',
                $activity->properties['reason'] ?? null,
            );
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

    private function createSsccParentLine(ReceivingSession $session, string $status): ReceivingScanLine
    {
        $epc = $this->createSsccEpc();
        // Sealed parent auto-confirm requires AggregationLink children.
        $this->attachSaleableChild($epc);

        return ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $epc->getKey(),
            'parent_epc_id' => null,
            'line_role' => 'parent',
            'status' => $status,
            'scan_raw' => $epc->epc_uri,
        ]);
    }

    private function attachSaleableChild(Epc $parent): Epc
    {
        do {
            $serial = (string) random_int(10_000_000_000_000, 99_999_999_999_999);
            $uri = 'urn:epc:id:sgtin:030116.0200116.'.$serial;
        } while (Epc::query()->where('epc_uri', $uri)->exists());

        $child = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->extraEpcIds[] = (int) $child->getKey();

        AggregationLink::query()->create([
            'parent_epc_id' => $parent->getKey(),
            'child_epc_id' => $child->getKey(),
            'established_by_event_id' => null,
            'link_type' => 'aggregation',
            'valid_from' => now(),
            'valid_to' => null,
        ]);

        return $child;
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

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) Str::uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function ensureUnknownGtinExceptionType(): void
    {
        ExceptionType::query()->updateOrCreate(
            ['code' => 'UNKNOWN_GTIN'],
            [
                'name' => 'Unknown / Unregistered GTIN',
                'category' => ExceptionTypeCategory::MasterData,
                'hda_class' => 'data_issues',
                'description' => 'GTIN not found in internal or partner master data',
                'default_severity' => ExceptionSeverity::High,
                'receive_impact' => ExceptionReceiveImpact::BusinessRule,
                'is_active' => true,
            ],
        );
    }

    private function resolveEligibleReceiveSiteId(): ?int
    {
        $site = EligibleReceiveSites::forOrganization()->first();

        return $site !== null ? (int) $site->getKey() : null;
    }

    private function epcAppearsOnSessionReceivingEvent(ReceivingSession $session, int $epcId): bool
    {
        $documentId = $session->receiving_epcis_document_id;
        if ($documentId === null) {
            return false;
        }

        $eventIds = EpcisEvent::query()
            ->where('document_id', $documentId)
            ->where(function ($query): void {
                $query->where('biz_step', 'like', '%:receiving')
                    ->orWhere('biz_step', 'like', '%:accepting');
            })
            ->pluck('id');

        if ($eventIds->isEmpty()) {
            return false;
        }

        return DB::table('event_epcs')
            ->where('epc_id', $epcId)
            ->whereIn('event_id', $eventIds)
            ->exists();
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

        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        $settings = TenantSettings::forTenant($tenant);
        $this->priorRequireTi = $settings->requireTiForScanFirst();
        $this->priorRequireAcceptRemainingReason = $settings->requireAcceptRemainingReason();
        $this->priorEdgeMode = $settings->receivingEdgeMode();
        // Normalize between tests so sealed-parent overrides do not leak into later suites.
        $settings->setReceivingEdgeMode(null);
        $settings->setRequireAcceptRemainingReason(false);
        $tenant->save();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->caseIds as $caseId) {
            $case = ExceptionCase::query()->find($caseId);
            if ($case === null) {
                continue;
            }
            $case->activities()->delete();
            QuarantineHold::query()->where('exception_id', $caseId)->delete();
            $case->epcs()->detach();
            $case->delete();
        }
        $this->caseIds = [];

        if ($this->holdIds !== []) {
            QuarantineHold::query()->whereIn('id', $this->holdIds)->delete();
            $this->holdIds = [];
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

        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        $this->deleteOrphanFixtureEpcs([self::SGTIN_URI, self::SSCC_URI]);

        if ($this->extraEpcIds !== []) {
            QuarantineHold::query()->whereIn('epc_id', $this->extraEpcIds)->delete();
            ReceivingScanLine::query()->whereIn('epc_id', $this->extraEpcIds)->delete();
            AggregationLink::query()
                ->whereIn('parent_epc_id', $this->extraEpcIds)
                ->orWhereIn('child_epc_id', $this->extraEpcIds)
                ->delete();
            Epc::query()->whereIn('id', $this->extraEpcIds)->delete();
            $this->extraEpcIds = [];
        }

        $settings = TenantSettings::forTenant($tenant);
        if ($this->priorRequireTi !== null) {
            $settings->setRequireTiForScanFirst($this->priorRequireTi);
        }
        if ($this->priorRequireAcceptRemainingReason !== null) {
            $settings->setRequireAcceptRemainingReason($this->priorRequireAcceptRemainingReason);
        }
        $settings->setReceivingEdgeMode($this->priorEdgeMode);
        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile]);
        }
        $tenant->save();
        $this->priorRequireTi = null;
        $this->priorRequireAcceptRemainingReason = null;
        $this->priorEdgeMode = null;
        $this->priorProfile = null;

        tenancy()->end();
    }
}
