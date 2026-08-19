<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmExpectedScanLineOnSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\CopyConfirmedReceivingScansToSession;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\Quarantine\QuarantineService;
use App\Support\Gs1\Gtin;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ResolveReceiveScanContext;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionCaseSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceivingAsnHierarchyAndGateFixesTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $sessionId = null;

    private ?int $asnSessionId = null;

    private ?int $extraSessionId = null;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    private ?bool $priorRequireTi = null;

    #[Test]
    public function reconcile_asn_parent_seeds_children_and_does_not_complete_without_them(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, self::SSCC_URI);
            $this->assertTrue($confirm['ok']);
            $this->assertSame($this->asnSessionId, (int) $confirm['reconciled_asn_session_id']);

            $asnSession->refresh();
            $this->assertSame(1, (int) $asnSession->confirmed_parent_count);
            $this->assertGreaterThan(0, (int) $asnSession->expected_child_count);
            $this->assertSame(0, (int) $asnSession->confirmed_child_count);
            $this->assertNotSame('completed', $asnSession->status);

            $this->assertSame(
                1,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->asnSessionId)
                    ->where('line_role', 'child')
                    ->where('status', 'expected')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function asn_parent_confirm_upgrades_pre_scanned_unexpected_children(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->asnSessionId = (int) $session->getKey();

            $childResult = app(ConfirmReceivingScan::class)->handle($session, self::SGTIN_URI);
            $this->assertFalse($childResult['ok']);
            $this->assertSame('unexpected', $childResult['effect']);

            $parentResult = app(ConfirmReceivingScan::class)->handle($session->fresh(), self::SSCC_URI);
            $this->assertTrue($parentResult['ok'], $parentResult['message'] ?? 'parent confirm failed');
            $this->assertSame('parent_confirmed', $parentResult['effect']);

            $childLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('epc_id', Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id'))
                ->first();

            $this->assertNotNull($childLine);
            $this->assertSame('child', $childLine->line_role);
            $this->assertNotSame('unexpected', $childLine->status);
            $this->assertContains($childLine->status, ['expected', 'confirmed']);
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function match_asn_copy_uses_strict_manifest_and_skips_off_manifest_epcs(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $offUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.OFF'.$suffix;
            $offEpc = Epc::query()->create(Epc::materializeAttributesFromUri($offUri));

            app(ConfirmReceivingScan::class)->handle($scanFirst, self::SSCC_URI);
            app(ConfirmReceivingScan::class)->handle($scanFirst->fresh(), $offUri);

            $copy = app(CopyConfirmedReceivingScansToSession::class)->handle(
                $scanFirst->fresh(),
                $asnSession->fresh(),
                null,
                strictManifestOnly: true,
            );

            $this->assertGreaterThan(0, $copy['skipped']);
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->asnSessionId)
                    ->where('epc_id', $offEpc->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function document_wide_exception_blocks_asn_confirm_and_complete(): void
    {
        $this->initializeDemo2Tenant();
        $this->seed(ExceptionCaseSeeder::class);

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->asnSessionId = (int) $session->getKey();

            $unknownGtin = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();
            $unknownGtin->forceFill(['receive_impact' => ExceptionReceiveImpact::BusinessRule])->save();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $unknownGtin->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Blocks confirm',
                'description' => 'Document-wide block',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($session->fresh(), self::SSCC_URI);
            $this->assertFalse($confirm['ok']);
            $this->assertSame('document_blocked', $confirm['effect']);

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'confirmed_parent_count' => 1,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
            ])->save();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('document-wide exception');
            app(CompleteReceivingSession::class)->handle($session->fresh());
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function find_unmatched_inbound_excludes_documents_with_completed_receiving_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.UM'.$suffix;

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now()->subHour(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'unmatched-complete.xml',
                'payload_disk' => 'local',
                'payload_path' => 'epcis/inbound/unmatched-complete-'.Str::uuid().'.xml',
                'dscsa_affirm' => true,
                'status' => 'validated',
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now()->subHour(),
            ]);
            $this->documentId = (int) $document->getKey();

            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));

            $shippingEvent = \App\Models\Epcis\EpcisEvent::query()->create([
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
                'event_id' => $shippingEvent->getKey(),
                'epc_id' => $epc->getKey(),
                'role' => 'epcList',
            ]);

            $before = app(ResolveReceiveScanContext::class)->handle($uri);
            $this->assertSame($this->documentId, (int) $before['matched_inbound_document_id']);

            $session = ReceivingSession::query()->create([
                'session_kind' => \App\Enums\ReceivingSessionKind::InboundAsn,
                'epcis_document_id' => $document->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now()->subMinute(),
                'completed_at' => now(),
            ]);
            $this->asnSessionId = (int) $session->getKey();

            $after = app(ResolveReceiveScanContext::class)->handle($uri);
            $this->assertTrue($after['ok']);
            $this->assertNull($after['matched_inbound_document_id']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function reopen_asn_updates_site_when_open_with_no_confirms(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            [$siteA, $siteB] = $this->createTwoReceiveSites();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, (int) $siteA->getKey());
            $this->asnSessionId = (int) $session->getKey();
            $this->assertSame((int) $siteA->getKey(), (int) $session->site_id);

            $reopened = app(OpenReceivingSessionFromDocument::class)->handle($document, (int) $siteB->getKey());
            $this->assertSame((int) $session->getKey(), (int) $reopened->getKey());
            $this->assertSame((int) $siteB->getKey(), (int) $reopened->site_id);
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function reopen_asn_rejects_site_change_after_confirms(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            [$siteA, $siteB] = $this->createTwoReceiveSites();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, (int) $siteA->getKey());
            $this->asnSessionId = (int) $session->getKey();

            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI);
            $this->assertGreaterThan(0, (int) $session->fresh()->confirmed_parent_count);

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('different site');
            app(OpenReceivingSessionFromDocument::class)->handle($document, (int) $siteB->getKey());
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function scan_first_complete_hard_blocks_when_require_ti_enabled(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->priorRequireTi = TenantSettings::forTenant($tenant)->requireTiForScanFirst();
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.TI'.$suffix;
            Epc::query()->create(Epc::materializeAttributesFromUri($uri));

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($session, $uri);
            $this->assertTrue($confirm['ok']);

            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(true);
            $tenant->save();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('TI required');
            app(CompleteReceivingSession::class)->handle($session->fresh());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function sealed_auto_confirm_reports_skipped_quarantined_children(): void
    {
        $this->initializeDemo2Tenant();
        $this->seed(ExceptionCaseSeeder::class);

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [(int) $child->id],
                reason: 'Quarantine before sealed confirm',
            );
            $this->caseIds[] = (int) $case->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->asnSessionId = (int) $session->getKey();

            $result = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                userId: null,
                autoConfirmChildren: true,
            );

            $this->assertTrue($result['ok']);
            $this->assertSame(1, (int) ($result['skipped_quarantined_children'] ?? 0));
            $this->assertStringContainsString('quarantined', strtolower($result['message']));
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->getKey())
                    ->where('epc_id', $child->id)
                    ->value('status'),
            );
            $this->assertNotSame('completed', $session->fresh()->status);
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function copy_skips_quarantined_epc_instead_of_mark_line_confirmed_fallback(): void
    {
        $this->initializeDemo2Tenant();
        $this->seed(ExceptionCaseSeeder::class);

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $parentConfirm = app(ConfirmReceivingScan::class)->handle($asnSession, self::SSCC_URI);
            $this->assertTrue($parentConfirm['ok'], $parentConfirm['message'] ?? 'parent confirm failed');
            $asnSession = $asnSession->fresh();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $asnSession->getKey())
                    ->where('epc_id', $child->getKey())
                    ->value('status'),
                'ASN child line must be seeded as expected before quarantine/copy',
            );

            app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [(int) $child->id],
                reason: 'Block copy fallback',
            );

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $scanFirst->getKey(),
                'epc_id' => $child->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => self::SGTIN_URI,
                'confirmed_at' => now(),
            ]);

            $result = app(CopyConfirmedReceivingScansToSession::class)->copyConfirmedEpc(
                $scanFirst->fresh(),
                $asnSession->fresh(),
                (int) $child->getKey(),
                null,
                strictManifestOnly: false,
            );

            $this->assertSame('skipped', $result['outcome']);
            $this->assertStringContainsString('quarantine', strtolower((string) $result['note']));
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $asnSession->getKey())
                    ->where('epc_id', $child->getKey())
                    ->value('status'),
            );
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function copy_skips_quarantined_off_manifest_epc_on_create_unexpected_confirmed(): void
    {
        $this->initializeDemo2Tenant();
        $this->seed(ExceptionCaseSeeder::class);

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $offUri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.OFF'.$suffix;
            $offEpc = Epc::query()->create(Epc::materializeAttributesFromUri($offUri));

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $scanFirst->getKey(),
                'epc_id' => $offEpc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => $offUri,
                'confirmed_at' => now(),
            ]);

            QuarantineHold::query()->create([
                'epc_id' => $offEpc->getKey(),
                'reason' => 'Block off-manifest copy',
                'status' => 'open',
                'severity' => 'warning',
                'opened_at' => now(),
            ]);

            $result = app(CopyConfirmedReceivingScansToSession::class)->copyConfirmedEpc(
                $scanFirst->fresh(),
                $asnSession->fresh(),
                (int) $offEpc->getKey(),
                null,
                strictManifestOnly: false,
            );

            $this->assertSame('skipped', $result['outcome']);
            $this->assertStringContainsString('quarantine', strtolower((string) $result['note']));
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $asnSession->getKey())
                    ->where('epc_id', $offEpc->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function confirm_expected_scan_line_rejects_quarantined_epc(): void
    {
        $this->initializeDemo2Tenant();
        $this->seed(ExceptionCaseSeeder::class);

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $parentConfirm = app(ConfirmReceivingScan::class)->handle($asnSession, self::SSCC_URI);
            $this->assertTrue($parentConfirm['ok'], $parentConfirm['message'] ?? 'parent confirm failed');
            $asnSession = $asnSession->fresh();

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [(int) $child->id],
                reason: 'Block expected-line confirm',
            );

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $sourceLine = ReceivingScanLine::query()->create([
                'receiving_session_id' => $scanFirst->getKey(),
                'epc_id' => $child->getKey(),
                'parent_epc_id' => Epc::query()->where('epc_uri', self::SSCC_URI)->value('id'),
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => self::SGTIN_URI,
                'confirmed_at' => now(),
            ]);

            $result = app(ConfirmExpectedScanLineOnSession::class)->handle(
                $asnSession,
                $sourceLine,
            );

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('quarantine', strtolower((string) $result['message']));
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $asnSession->getKey())
                    ->where('epc_id', $child->getKey())
                    ->value('status'),
            );
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function confirm_expected_scan_line_rejects_document_wide_exception(): void
    {
        $this->initializeDemo2Tenant();
        $this->seed(ExceptionCaseSeeder::class);

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $unknownGtin = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();
            $unknownGtin->forceFill(['receive_impact' => ExceptionReceiveImpact::BusinessRule])->save();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $unknownGtin->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Blocks expected-line confirm',
                'description' => 'Document-wide block',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $sourceLine = ReceivingScanLine::query()->create([
                'receiving_session_id' => $scanFirst->getKey(),
                'epc_id' => $parent->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'parent',
                'status' => 'confirmed',
                'scan_raw' => self::SSCC_URI,
                'confirmed_at' => now(),
            ]);

            $result = app(ConfirmExpectedScanLineOnSession::class)->handle(
                $asnSession->fresh(),
                $sourceLine,
            );

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('document-wide exception', strtolower((string) $result['message']));
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $asnSession->getKey())
                    ->where('epc_id', $parent->getKey())
                    ->value('status'),
            );
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function confirm_expected_scan_line_rejects_child_without_parent_confirmed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $asnSession->getKey(),
                'epc_id' => $child->getKey(),
                'parent_epc_id' => $parent->getKey(),
                'line_role' => 'child',
                'status' => 'expected',
            ]);

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            $sourceLine = ReceivingScanLine::query()->create([
                'receiving_session_id' => $scanFirst->getKey(),
                'epc_id' => $child->getKey(),
                'parent_epc_id' => $parent->getKey(),
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => self::SGTIN_URI,
                'confirmed_at' => now(),
            ]);

            $result = app(ConfirmExpectedScanLineOnSession::class)->handle(
                $asnSession->fresh(),
                $sourceLine,
            );

            $this->assertFalse($result['ok']);
            $this->assertSame('Confirm the pallet before scanning units.', $result['message']);
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $asnSession->getKey())
                    ->where('epc_id', $child->getKey())
                    ->value('status'),
            );
        } finally {
            $this->cleanup(null);
        }
    }

    #[Test]
    public function copy_skips_child_when_parent_not_confirmed_and_line_stays_expected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $parent = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $asnSession->getKey(),
                'epc_id' => $child->getKey(),
                'parent_epc_id' => $parent->getKey(),
                'line_role' => 'child',
                'status' => 'expected',
            ]);

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $scanFirst->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $scanFirst->getKey(),
                'epc_id' => $child->getKey(),
                'parent_epc_id' => $parent->getKey(),
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => self::SGTIN_URI,
                'confirmed_at' => now(),
            ]);

            $result = app(CopyConfirmedReceivingScansToSession::class)->copyConfirmedEpc(
                $scanFirst->fresh(),
                $asnSession->fresh(),
                (int) $child->getKey(),
                null,
                strictManifestOnly: true,
            );

            $this->assertSame('skipped', $result['outcome']);
            $this->assertStringContainsString('pallet', strtolower((string) $result['note']));
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $asnSession->getKey())
                    ->where('epc_id', $child->getKey())
                    ->value('status'),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function copy_blocks_off_manifest_when_epc_on_another_open_receive_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->asnSessionId = (int) $asnSession->getKey();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.CP'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));

            $sourceScanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionId = (int) $sourceScanFirst->getKey();

            ReceivingScanLine::query()->create([
                'receiving_session_id' => $sourceScanFirst->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => $uri,
                'confirmed_at' => now(),
            ]);

            $conflictingScanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->extraSessionId = (int) $conflictingScanFirst->getKey();
            ReceivingScanLine::query()->create([
                'receiving_session_id' => $conflictingScanFirst->getKey(),
                'epc_id' => $epc->getKey(),
                'parent_epc_id' => null,
                'line_role' => 'child',
                'status' => 'confirmed',
                'scan_raw' => $uri,
                'confirmed_at' => now(),
            ]);

            $result = app(CopyConfirmedReceivingScansToSession::class)->copyConfirmedEpc(
                $sourceScanFirst->fresh(),
                $asnSession->fresh(),
                (int) $epc->getKey(),
                null,
                strictManifestOnly: false,
            );

            $this->assertSame('skipped', $result['outcome']);
            $this->assertStringContainsString(
                'another open receive session',
                strtolower((string) $result['note']),
            );
            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $asnSession->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->where('status', 'confirmed')
                    ->count(),
            );
        } finally {
            $this->cleanup($tenant);
        }
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

    private function resolveEligibleReceiveSiteId(): ?int
    {
        $sites = app(EligibleReceiveSites::class)->options();

        return $sites === [] ? null : (int) array_key_first($sites);
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createTwoReceiveSites(): array
    {
        $siteA = Site::query()->create([
            'name' => 'ASN Gate Site A '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $siteA->getKey();

        $siteB = Site::query()->create([
            'name' => 'ASN Gate Site B '.Str::random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $siteB->getKey();

        return [$siteA, $siteB];
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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

        $this->priorRequireTi = TenantSettings::forTenant($tenant)->requireTiForScanFirst();

        return $tenant;
    }

    private function cleanup(?Tenant $tenant): void
    {
        if (tenancy()->initialized) {
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

            foreach (array_filter([$this->sessionId, $this->asnSessionId, $this->extraSessionId]) as $sessionId) {
                $session = ReceivingSession::query()->find($sessionId);
                if ($session?->receiving_epcis_document_id !== null) {
                    EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
                }
                ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }
            $this->sessionId = null;
            $this->asnSessionId = null;
            $this->extraSessionId = null;

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

            foreach ($this->siteIds as $siteId) {
                Site::query()->whereKey($siteId)->delete();
            }
            $this->siteIds = [];

            foreach ([self::SGTIN_URI, self::SSCC_URI] as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null) {
                    QuarantineHold::query()->where('epc_id', $epc->id)->delete();
                }
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
            }

            if ($tenant !== null && $this->priorRequireTi !== null) {
                TenantSettings::forTenant($tenant)->setRequireTiForScanFirst($this->priorRequireTi);
                $tenant->save();
            }
            $this->priorRequireTi = null;

            tenancy()->end();
        }
    }
}
