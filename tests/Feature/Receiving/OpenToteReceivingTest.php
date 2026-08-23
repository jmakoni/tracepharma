<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CloseOpenToteReceiving;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
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
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class OpenToteReceivingTest extends TestCase
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
    private array $holdIds = [];

    /** @var list<int> */
    private array $extraEpcIds = [];

    private ?bool $priorRequireTi = null;

    private ?ReceivingEdgeMode $priorEdgeMode = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function parent_scan_locks_tote_and_does_not_confirm_children(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenTote);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertSame(ReceivingEdgeMode::OpenTote, $policy->edgeMode());
            $this->assertFalse($policy->defaultAutoConfirmChildren());

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );

            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');

            $parentEpcId = (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');
            $session = $session->fresh();

            $this->assertSame($parentEpcId, (int) $session->active_parent_epc_id);

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
    public function locked_child_confirms_and_serial_from_another_tote_is_unexpected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenTote);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $siblingParent = $this->createSsccParentLine($session, 'expected');
            $siblingChild = $this->createChildLine($session, (int) $siblingParent->epc_id);
            $session->increment('expected_parent_count');

            $policy = ReceivingPolicy::forTenant($tenant);

            $parentConfirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentConfirm['ok'], $parentConfirm['message'] ?? 'parent confirm failed');

            $lockedParentId = (int) $session->fresh()->active_parent_epc_id;
            $this->assertSame(
                (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id'),
                $lockedParentId,
            );

            $childConfirm = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                self::SGTIN_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($childConfirm['ok'], $childConfirm['message'] ?? 'child confirm failed');
            $this->assertSame('child_confirmed', $childConfirm['effect']);

            $lockedChild = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->where('epc_id', Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id'))
                ->first();
            $this->assertNotNull($lockedChild);
            $this->assertSame('confirmed', $lockedChild->status);
            $this->assertSame($lockedParentId, (int) $lockedChild->parent_epc_id);

            $comingled = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                (string) $siblingChild->epc?->epc_uri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );

            $this->assertFalse($comingled['ok']);
            $this->assertSame('comingling', $comingled['effect']);
            $this->assertSame('Unit belongs to another tote — not confirmed.', $comingled['message']);
            $this->assertStringNotContainsString('ASN', (string) $comingled['message']);
            $this->assertStringNotContainsString('Unexpected', (string) $comingled['message']);

            $siblingChild = $siblingChild->fresh();
            $this->assertSame('expected', $siblingChild->status);
            $this->assertNotSame('confirmed', $siblingChild->status);
            $this->assertNotSame($lockedParentId, (int) $siblingChild->parent_epc_id);
            $this->assertSame((int) $siblingParent->epc_id, (int) $siblingChild->parent_epc_id);
            $this->assertIsArray($siblingChild->ilmd_mismatch_json);
            $this->assertArrayHasKey('comingling', $siblingChild->ilmd_mismatch_json);
            $this->assertSame($lockedParentId, (int) $siblingChild->ilmd_mismatch_json['comingling']['scanned_while_parent_epc_id']);
            $this->assertSame((int) $siblingParent->epc_id, (int) $siblingChild->ilmd_mismatch_json['comingling']['line_parent_epc_id']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function last_child_complete_clears_open_tote_lock(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenTote);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);

            $parentConfirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentConfirm['ok'], $parentConfirm['message'] ?? 'parent confirm failed');
            $this->assertNotNull($session->fresh()->active_parent_epc_id);

            $childConfirm = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                self::SGTIN_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($childConfirm['ok'], $childConfirm['message'] ?? 'child confirm failed');

            $session = $session->fresh();
            $this->assertSame('completed', $session->status);
            $this->assertNull($session->active_parent_epc_id);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function close_last_tote_completes_with_unpack(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenTote);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $policy = ReceivingPolicy::forTenant($tenant);
            $this->assertTrue($policy->canUnpackAtReceive());

            $parentConfirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentConfirm['ok'], $parentConfirm['message'] ?? 'parent confirm failed');

            app(CloseOpenToteReceiving::class)->handle($session->fresh(), null, unpack: true);

            $session = $session->fresh();
            $this->assertSame('completed', $session->status);
            $this->assertNull($session->active_parent_epc_id);
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
    public function second_expected_parent_is_rejected_until_close_tote(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenTote);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $secondParent = $this->createSsccParentLine($session, 'expected');
            $session->increment('expected_parent_count');

            $policy = ReceivingPolicy::forTenant($tenant);

            $first = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($first['ok'], $first['message'] ?? 'parent confirm failed');

            $lockedParent = Epc::query()->where('epc_uri', self::SSCC_URI)->first();
            $this->assertNotNull($lockedParent);
            $toteLabel = filled($lockedParent->sscc18) ? (string) $lockedParent->sscc18 : self::SSCC_URI;

            $second = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                (string) $secondParent->epc?->epc_uri,
                null,
                $policy->defaultAutoConfirmChildren(),
            );

            $this->assertFalse($second['ok']);
            $this->assertSame('Close tote '.$toteLabel.' first', $second['message']);
            $this->assertSame('expected', $secondParent->fresh()->status);
            $this->assertSame(
                (int) $lockedParent->getKey(),
                (int) $session->fresh()->active_parent_epc_id,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function shortage_close_clears_lock_and_complete_stays_gated_when_other_parents_remain(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setEdgeMode($tenant, ReceivingEdgeMode::OpenTote);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $this->sessionId = (int) $session->getKey();

            $otherParent = $this->createSsccParentLine($session, 'expected');
            $this->createChildLine($session, (int) $otherParent->epc_id);
            $session->increment('expected_parent_count');
            $session->increment('expected_child_count');

            $policy = ReceivingPolicy::forTenant($tenant);

            $parentConfirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($parentConfirm['ok'], $parentConfirm['message'] ?? 'parent confirm failed');

            $parentEpcId = (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');
            $this->assertSame($parentEpcId, (int) $session->fresh()->active_parent_epc_id);

            $child = ReceivingScanLine::query()
                ->where('receiving_session_id', $this->sessionId)
                ->where('epc_id', Epc::query()->where('epc_uri', self::SGTIN_URI)->value('id'))
                ->first();
            $this->assertNotNull($child);
            $this->assertSame('expected', $child->status);

            $closed = app(CloseOpenToteReceiving::class)->handle($session->fresh());
            $this->assertTrue($closed['short_closed']);

            $session = $session->fresh();
            $this->assertNull($session->active_parent_epc_id);
            $this->assertContains($parentEpcId, array_map('intval', $session->short_closed_parent_epc_ids ?? []));
            $this->assertSame('expected', $child->fresh()->status);
            $this->assertSame('expected', $otherParent->fresh()->status);
            $this->assertNotSame('completed', $session->status);

            try {
                app(CompleteReceivingSession::class)->handle($session->fresh());
            } catch (DomainException) {
                // Complete may throw when other parents remain.
            }

            $this->assertNotSame('completed', $session->fresh()->status);

            $leftover = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                self::SGTIN_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertFalse($leftover['ok']);
            $this->assertSame('expected', $child->fresh()->status);
            $this->assertContains($parentEpcId, array_map('intval', $session->fresh()->short_closed_parent_epc_ids ?? []));
            $this->assertNull($session->fresh()->active_parent_epc_id);

            $next = app(ConfirmReceivingScan::class)->handle(
                $session->fresh(),
                (string) $otherParent->scan_raw,
                null,
                $policy->defaultAutoConfirmChildren(),
            );
            $this->assertTrue($next['ok'], $next['message'] ?? 'next parent confirm failed');
            $this->assertSame((int) $otherParent->epc_id, (int) $session->fresh()->active_parent_epc_id);
            $this->assertSame('expected', $child->fresh()->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function sealed_parent_sessions_never_write_active_parent_epc_id(): void
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

            $confirm = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                null,
                $policy->defaultAutoConfirmChildren(),
            );

            $this->assertTrue($confirm['ok'], $confirm['message'] ?? 'parent confirm failed');
            $this->assertNull($session->fresh()->active_parent_epc_id);
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

    private function createChildLine(ReceivingSession $session, int $parentEpcId): ReceivingScanLine
    {
        $epc = $this->createSgtinEpc();

        return ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $epc->getKey(),
            'parent_epc_id' => $parentEpcId,
            'line_role' => 'child',
            'status' => 'expected',
            'scan_raw' => $epc->epc_uri,
        ])->load('epc');
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
