<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\ConfirmRemainingExpectedReceivingLines;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
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
use App\Models\Tenant;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingEdgeMode;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantSettings;
use Database\Seeders\ExceptionCaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
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

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle($session->fresh());

            $this->assertGreaterThanOrEqual(2, $result['confirmed']);
            $this->assertSame([], $result['blockers'], implode(' | ', $result['blockers']));

            $child = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->where('epc_id', Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id'))
                ->first();

            $this->assertNotNull($child);
            $this->assertSame('child', $child->line_role);
            $this->assertSame('confirmed', $child->status);

            $session = $session->fresh();
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

            $this->assertSame(2, $result['confirmed']);
            $this->assertSame(1, $result['skipped']);
            $this->assertIsArray($result['blockers']);

            $this->assertSame(
                'confirmed',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $this->sessionId)
                    ->where('epc_id', Epc::query()->where('epc_uri', self::SSCC_URI)->value('id'))
                    ->value('status'),
            );
            $this->assertSame('confirmed', $secondParent->fresh()->status);
            $this->assertSame('expected', $quarantinedParent->fresh()->status);
            $this->assertSame('unexpected', $unexpectedParent->fresh()->status);
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

            $result = app(ConfirmRemainingExpectedReceivingLines::class)->handle(
                $session->fresh(),
                null,
                unpack: true,
            );

            $this->assertGreaterThanOrEqual(1, $result['confirmed']);
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
        $this->seed(ExceptionCaseSeeder::class);

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

    private function setEdgeMode(Tenant $tenant, ReceivingEdgeMode $mode): void
    {
        TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
        TenantSettings::forTenant($tenant)->setReceivingEdgeMode($mode);
        $tenant->save();
    }

    private function createSsccParentLine(ReceivingSession $session, string $status): ReceivingScanLine
    {
        $epc = $this->createSsccEpc();

        return ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $epc->getKey(),
            'parent_epc_id' => null,
            'line_role' => 'parent',
            'status' => $status,
            'scan_raw' => $epc->epc_uri,
        ]);
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

        $this->prepareDemo2ReceivingState([self::SSCC_URI, self::SGTIN_URI]);

        $settings = TenantSettings::forTenant($tenant);
        $this->priorRequireTi = $settings->requireTiForScanFirst();
        $this->priorEdgeMode = $settings->receivingEdgeMode();

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
            Epc::query()->whereIn('id', $this->extraEpcIds)->delete();
            $this->extraEpcIds = [];
        }

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
